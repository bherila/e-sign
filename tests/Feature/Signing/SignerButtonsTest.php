<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Envelopes\SignerButtons;
use App\Domain\Signing\Exceptions\DeclineNotAllowed;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * The signer's Decline and Finish later buttons (issue #165).
 *
 * Resolved envelope, then workspace, then the deployment default; a template's values are
 * copied onto an envelope when it is built. With `allow_decline` false the state machine
 * refuses a decline, whichever surface asks — the hidden button is a courtesy, not the rule.
 */
class SignerButtonsTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    private const FACADE = '/functions/v1/signing-request-api/signing-requests';

    public function test_both_buttons_are_offered_by_default(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $payload = $this->payloadFrom(
            $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope))->getContent(),
        );

        $this->assertSame(['allow_decline' => true, 'show_save_for_later' => true], $payload['buttons']);
    }

    public function test_a_decline_is_refused_when_the_envelope_does_not_allow_one(): void
    {
        $scenario = GuestSigningScenario::sent();
        $scenario->envelope->forceFill(['allow_decline' => false, 'show_save_for_later' => false])->save();
        $cookie = $this->startSigningSession($scenario);

        $payload = $this->payloadFrom(
            $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope))->getContent(),
        );
        $this->assertSame(['allow_decline' => false, 'show_save_for_later' => false], $payload['buttons']);

        // Posted anyway: refused by the state machine, and nothing changes.
        $this->asSigner($cookie)
            ->post(GuestSigningScenario::sessionUrl($scenario->envelope, '/decline'), ['decline_confirmed' => '1'])
            ->assertStatus(409)
            ->assertSee('cannot be declined here');

        $this->assertSame(EnvelopeState::Sent, $scenario->envelope->refresh()->state);
        $this->assertSame(RecipientState::Active, $scenario->recipient()->refresh()->state);
    }

    public function test_the_state_machine_itself_refuses_the_decline(): void
    {
        $scenario = GuestSigningScenario::sent();
        $scenario->envelope->forceFill(['allow_decline' => false])->save();

        $this->expectException(DeclineNotAllowed::class);

        $this->app->make(EnvelopeStateMachine::class)->decline($scenario->recipient());
    }

    public function test_the_envelope_wins_then_the_workspace_then_the_deployment(): void
    {
        $scenario = GuestSigningScenario::sent();
        $envelope = $scenario->envelope;
        $workspace = $envelope->workspace()->firstOrFail();
        $buttons = new SignerButtons(config());

        config()->set('esign.signing.allow_decline', false);
        $this->assertFalse($buttons->allowDecline($envelope), 'Nothing set: the deployment default.');

        $workspace->forceFill(['allow_decline' => true])->save();
        $this->assertTrue($buttons->allowDecline($envelope->refresh()), 'The workspace overrides the deployment.');

        $envelope->forceFill(['allow_decline' => false])->save();
        $this->assertFalse($buttons->allowDecline($envelope->refresh()), 'The envelope overrides the workspace.');

        $envelope->forceFill(['allow_decline' => null])->save();
        $workspace->forceFill(['allow_decline' => null])->save();
        config()->set('esign.signing.allow_decline', true);
        $this->assertTrue($buttons->allowDecline($envelope->refresh()));
    }

    public function test_a_template_value_is_copied_and_a_value_sent_replaces_it(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();
        $version->template->forceFill(['allow_decline' => false, 'show_save_for_later' => false])->save();

        $copied = $this->createFromTemplate($version->template->public_id, $issued, []);
        $this->assertFalse($copied->allow_decline);
        $this->assertFalse($copied->show_save_for_later);

        // A value sent replaces the template's; `null` means the workspace decides.
        $replaced = $this->createFromTemplate($version->template->public_id, $issued, [
            'allow_decline' => true,
            'show_save_for_later' => null,
        ]);
        $this->assertTrue($replaced->allow_decline);
        $this->assertNull($replaced->show_save_for_later);

        // Changing the template later changes nothing already built.
        $version->template->forceFill(['allow_decline' => true])->save();
        $this->assertFalse($copied->refresh()->allow_decline);
    }

    public function test_the_facade_echoes_the_stored_values_and_refuses_a_non_boolean(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();

        $envelope = $this->createFromTemplate($version->template->public_id, $issued, ['allow_decline' => false]);

        $this->getJson(self::FACADE.'/'.$envelope->public_id, FirmaFacadeScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('settings.allow_decline', false)
            ->assertJsonPath('settings.show_save_for_later', null);

        $this->postJson(self::FACADE, [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic refused',
            'recipients' => $this->recipients(),
            'settings' => ['allow_decline' => 'no'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request')
            ->assertJsonPath('message', 'settings.allow_decline must be a boolean or null.');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createFromTemplate(string $templateId, mixed $issued, array $settings): Envelope
    {
        $body = [
            'template_id' => $templateId,
            'name' => 'Synthetic NDA with signer buttons',
            'recipients' => $this->recipients(),
        ];

        if ($settings !== []) {
            $body['settings'] = $settings;
        }

        $response = $this->postJson(self::FACADE, $body, FirmaFacadeScenario::headers($issued))->assertStatus(201);

        return Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function recipients(): array
    {
        return [
            ['template_user_id' => 'buyer', 'first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test', 'designation' => 'Signer', 'order' => 1],
            ['template_user_id' => 'seller', 'first_name' => 'Sam', 'last_name' => 'Seller', 'email' => 'sam@seller.example.test', 'designation' => 'Signer', 'order' => 2],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFrom(string|false $html): array
    {
        $matched = preg_match('/data-signing="(.*?)"/s', (string) $html, $matches);

        $this->assertSame(1, $matched, 'The signing page carried no payload attribute.');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
