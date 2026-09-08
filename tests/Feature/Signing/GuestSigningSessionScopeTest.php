<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Sessions\Models\SigningSession;
use App\Domain\Signing\Sessions\SigningToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * What a signing session does and does not authorize.
 *
 * docs/HANDOFF.md section 8: credentials are "scoped to one recipient and envelope". A
 * session is the second half of that, and the scope check is in one place — the middleware —
 * rather than in six controllers, so it cannot be forgotten on the seventh.
 *
 * The review page and the document stream are covered here too, because what they *show* is
 * the other half of the same question: a guest gets this envelope's review revision, through
 * a route only their own session opens, and never the administrative download endpoints.
 */
class GuestSigningSessionScopeTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_a_session_for_one_envelope_does_not_open_another(): void
    {
        $mine = GuestSigningScenario::sent();
        $theirs = GuestSigningScenario::sent();

        $cookie = $this->startSigningSession($mine);

        $this->asSigner($cookie)
            ->get(GuestSigningScenario::sessionUrl($theirs->envelope))
            ->assertForbidden();

        $this->asSigner($cookie)
            ->get(GuestSigningScenario::sessionUrl($theirs->envelope, '/document'))
            ->assertForbidden();

        $this->asSigner($cookie)
            ->postJson(GuestSigningScenario::sessionUrl($theirs->envelope, '/values'), [
                'values' => ['buyer_ack' => true],
            ])
            ->assertForbidden();
    }

    public function test_an_invented_cookie_authorizes_nothing(): void
    {
        $scenario = GuestSigningScenario::sent();

        $this->asSigner(SigningToken::generate())
            ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
            ->assertForbidden();
    }

    public function test_a_public_recipient_identifier_alone_authorizes_nothing(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        // The compatibility resolver renders a form and nothing else; there is no route
        // anywhere that turns this identifier into a signing session on its own.
        $this->get('/signing/'.$recipient->public_id)->assertOk()->assertDontSee($scenario->envelope->title);
        $this->assertSame(0, SigningSession::query()->count());
    }

    public function test_the_review_page_shows_the_agreement_and_its_consent_notice(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));

        $response->assertOk();
        $response->assertSee($scenario->envelope->title);
        $response->assertSee('data-signing', false);

        $payload = $this->payloadFrom($response->getContent());

        $this->assertSame($scenario->envelope->public_id, $payload['envelope']['id']);
        $this->assertSame($scenario->recipient()->public_id, $payload['recipient']['id']);
        $this->assertSame(
            ['buyer_ack', 'buyer_notes', 'buyer_signature'],
            $payload['own_field_ids'],
            'Read-only and service-supplied fields are not the recipient\'s to complete.',
        );
        $this->assertSame(['buyer_signature'], $payload['required_signature_field_ids']);
        $this->assertSame(['seller_signed_at'], $payload['service_supplied_field_ids']);
        $this->assertNotSame('', $payload['consent']['html']);
        $this->assertSame($scenario->envelope->consent_policy_version, $payload['consent']['recorded_version']);
        $this->assertCount(2, $payload['pages']);
    }

    public function test_the_page_says_so_when_the_notice_is_not_the_version_the_envelope_recorded(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        // The fixture envelope records `consent-2026-01`; the deployment's notice is whatever
        // ESIGN_CONSENT_POLICY_VERSION says. Rendering current wording under an older
        // version's name would make the attestation a record of something that did not happen.
        config()->set('esign.signing.consent_policy_version', 'a-different-version');

        $payload = $this->payloadFrom(
            $this->asSigner($cookie)
                ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
                ->getContent(),
        );

        $this->assertFalse($payload['consent']['matches_recorded_version']);
        $this->assertSame('a-different-version', $payload['consent']['text_version']);

        config()->set('esign.signing.consent_policy_version', $scenario->envelope->consent_policy_version);

        $payload = $this->payloadFrom(
            $this->asSigner($cookie)
                ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
                ->getContent(),
        );

        $this->assertTrue($payload['consent']['matches_recorded_version']);
    }

    public function test_the_page_carries_no_other_partys_address(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));

        $seller = $scenario->recipient('seller');

        $response->assertSee($seller->name);
        $response->assertDontSee($seller->email);
    }

    public function test_the_review_digest_the_page_states_is_the_one_the_state_machine_checks(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope));
        $payload = $this->payloadFrom($response->getContent());

        $this->assertSame(
            app(EnvelopeStateMachine::class)->materialValuesDigest($scenario->envelope->refresh()),
            $payload['reviewed']['material_values_sha256'],
        );
        $this->assertSame($scenario->envelope->version, $payload['reviewed']['envelope_version']);
    }

    public function test_the_document_route_streams_the_envelopes_review_revision(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $response = $this->asSigner($cookie)
            ->get(GuestSigningScenario::sessionUrl($scenario->envelope, '/document'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Document-Sha256', $scenario->signing->revision->sha256);
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(GuestSigningScenario::DOCUMENT_BYTES, $response->streamedContent());
    }

    public function test_the_document_route_needs_the_signing_session(): void
    {
        $scenario = GuestSigningScenario::sent();

        $this->get(GuestSigningScenario::sessionUrl($scenario->envelope, '/document'))->assertForbidden();
    }

    public function test_a_recipient_whose_turn_has_not_come_is_told_so_rather_than_shown_a_form(): void
    {
        $scenario = GuestSigningScenario::sent();
        $later = $scenario->recipient('seller');

        // A later signer's invitation resolves from the moment the envelope is sent — plenty
        // of deployments mail everything at once — so the ordering guard has to be in the
        // domain rather than in whether a link works (docs/signing/state-machine.md).
        $issued = $scenario->invite($later);

        $this->get(GuestSigningScenario::landingUrl($issued))->assertForbidden();
        $this->assertSame(0, SigningSession::query()->count());
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
