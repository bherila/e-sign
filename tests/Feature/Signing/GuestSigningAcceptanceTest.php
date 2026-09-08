<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Envelopes\VerificationMethod;
use App\Domain\Signing\Fields\ClientEvidence;
use App\Domain\Signing\Models\RecipientAttestation;
use App\Domain\Signing\Sessions\Models\SigningSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\Support\SyntheticImages;
use Tests\TestCase;

/**
 * The assent itself: what it records, what it refuses, and what a retry does.
 *
 * The state machine owns every rule here and is already covered by
 * `tests/Feature/Signing/EnvelopeAcceptanceTest.php`. What this suite is for is the guest
 * surface's half — that the page states what it displayed, that a stale review reaches the
 * person as an instruction to re-read rather than as a failure, and that the evidence the
 * session contributes is the minimized kind docs/HANDOFF.md section 8 asks for and nothing
 * more.
 */
class GuestSigningAcceptanceTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_a_signer_completes_their_fields_and_signs(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);

        $response = $this->accept($scenario, $cookie, $reviewed);

        $response->assertRedirect(GuestSigningScenario::sessionUrl($scenario->envelope));

        $recipient = $scenario->recipient()->refresh();
        $this->assertSame(RecipientState::Signed, $recipient->state);

        $attestation = RecipientAttestation::query()->where('recipient_id', $recipient->getKey())->firstOrFail();
        $this->assertSame($scenario->envelope->consent_policy_version, $attestation->consent_policy_version);
        $this->assertSame(VerificationMethod::EmailLink, $attestation->verification_method);
        $this->assertSame(
            $reviewed['reviewed_material_sha256'],
            $attestation->material_values_sha256,
        );

        // The envelope has a second signer, so it moves on rather than finishing.
        $this->assertSame(EnvelopeState::InProgress, $scenario->envelope->refresh()->state);
    }

    public function test_the_attestation_records_the_session_and_minimized_client_evidence(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);

        $this->accept($scenario, $cookie, $reviewed)->assertRedirect();

        $attestation = RecipientAttestation::query()->firstOrFail();
        $session = SigningSession::query()->firstOrFail();

        $this->assertSame($session->public_id, $attestation->session_ref);
        $this->assertSame('guest_signing_page', $attestation->client_evidence['channel'] ?? null);
        $this->assertArrayHasKey('ip', $attestation->client_evidence);

        // The allowlist is the whole shape of the record: nothing a page could compute about
        // fonts, canvases, or hardware, and no cookie or session identifier.
        $this->assertSame(
            [],
            array_diff(
                array_keys($attestation->client_evidence),
                ClientEvidence::ALLOWED_KEYS,
            ),
        );
    }

    public function test_the_confirmation_page_shows_the_server_acceptance_time(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);

        $this->accept($scenario, $cookie, $reviewed);

        $attestation = RecipientAttestation::query()->firstOrFail();

        $response = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));

        $response->assertOk();
        $response->assertSee('You have signed this agreement');
        $response->assertSee($attestation->accepted_at->utc()->format('j M Y, H:i:s').' UTC');
        $response->assertSee('sealed');
        // Honest language: the seal is the service's, not a personal certificate.
        $response->assertSee('not a personal signing certificate');
    }

    public function test_signing_twice_in_one_session_records_one_acceptance(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);

        $this->accept($scenario, $cookie, $reviewed)->assertRedirect();
        $this->accept($scenario, $cookie, $reviewed)->assertRedirect();

        $this->assertSame(1, RecipientAttestation::query()->count());
    }

    public function test_a_review_that_has_moved_is_a_409_asking_the_signer_to_read_it_again(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);

        // The sender corrects a material value after the page was rendered.
        app(EnvelopeStateMachine::class)
            ->setSenderValues($scenario->envelope->refresh(), ['agreement_effective_date' => '2026-03-03']);

        $response = $this->accept($scenario, $cookie, $reviewed);

        $response->assertStatus(409);
        $response->assertSee('The agreement changed while you were reading it');
        $response->assertSee('Review the current version');

        $this->assertSame(0, RecipientAttestation::query()->count());
        $this->assertSame(RecipientState::Active, $scenario->recipient()->refresh()->state);
    }

    public function test_a_consent_version_that_is_not_the_envelopes_is_refused(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);
        $reviewed['consent_version'] = 'consent-from-somewhere-else';

        $this->accept($scenario, $cookie, $reviewed)->assertStatus(409);
        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_signing_is_refused_while_a_required_signature_box_is_empty(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        // Everything except the signature.
        $reviewed = $this->saveValues($scenario, $cookie, ['buyer_ack' => true]);

        $response = $this->accept($scenario, $cookie, $reviewed);

        $response->assertRedirect();
        $response->assertSessionHasErrors('signature_field_ids');
        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_a_shortened_signature_field_list_is_refused(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);
        $reviewed['signature_field_ids'] = [];

        $response = $this->accept($scenario, $cookie, $reviewed);

        $response->assertRedirect();
        $response->assertSessionHasErrors('signature_field_ids');
        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_consent_and_intent_are_two_separate_statements(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $reviewed = $this->fillRequiredFields($scenario, $cookie);

        $withoutIntent = $reviewed;
        unset($withoutIntent['intent_confirmed']);
        $this->accept($scenario, $cookie, $withoutIntent)->assertSessionHasErrors('intent_confirmed');

        $withoutConsent = $reviewed;
        unset($withoutConsent['consent_accepted']);
        $this->accept($scenario, $cookie, $withoutConsent)->assertSessionHasErrors('consent_accepted');

        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_declining_ends_the_agreement_for_everybody(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)->post(
            GuestSigningScenario::sessionUrl($scenario->envelope, '/decline'),
            ['decline_confirmed' => '1', 'reason' => 'The named signatory has left.'],
        );

        $response->assertRedirect(GuestSigningScenario::sessionUrl($scenario->envelope));

        $this->assertSame(EnvelopeState::Declined, $scenario->envelope->refresh()->state);
        $this->assertSame(RecipientState::Declined, $scenario->recipient()->refresh()->state);

        $page = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));
        $page->assertOk();
        $page->assertSee('You declined this agreement');
        $page->assertSee('The named signatory has left.');
    }

    public function test_declining_needs_an_explicit_confirmation(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $this->asSigner($cookie)
            ->post(GuestSigningScenario::sessionUrl($scenario->envelope, '/decline'), ['reason' => 'No'])
            ->assertSessionHasErrors('decline_confirmed');

        $this->assertSame(EnvelopeState::Sent, $scenario->envelope->refresh()->state);
    }

    public function test_signing_is_refused_without_a_session(): void
    {
        $scenario = GuestSigningScenario::sent();

        $this->post(GuestSigningScenario::sessionUrl($scenario->envelope, '/accept'), [])
            ->assertForbidden();

        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    /**
     * Fill everything the buyer must supply, and return the accept form's payload.
     *
     * @return array<string, mixed>
     */
    private function fillRequiredFields(GuestSigningScenario $scenario, string $cookie): array
    {
        return $this->saveValues($scenario, $cookie, [
            'buyer_ack' => true,
            'buyer_signature' => SyntheticImages::pngDataUrl(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function saveValues(GuestSigningScenario $scenario, string $cookie, array $values): array
    {
        $response = $this->asSigner($cookie)->postJson(
            GuestSigningScenario::sessionUrl($scenario->envelope, '/values'),
            ['values' => $values],
        );

        $response->assertOk();

        return [
            'consent_accepted' => '1',
            'intent_confirmed' => '1',
            'consent_version' => $scenario->envelope->refresh()->consent_policy_version,
            'reviewed_material_sha256' => (string) $response->json('reviewed.material_values_sha256'),
            'reviewed_envelope_version' => (int) $response->json('reviewed.envelope_version'),
            'signature_field_ids' => ['buyer_signature'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function accept(GuestSigningScenario $scenario, string $cookie, array $payload): TestResponse
    {
        return $this->asSigner($cookie)->post(
            GuestSigningScenario::sessionUrl($scenario->envelope, '/accept'),
            $payload,
        );
    }
}
