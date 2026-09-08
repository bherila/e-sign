<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Models\RecipientAttestation;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Domain\Signing\Sessions\Models\SigningOtpChallenge;
use App\Domain\Signing\Sessions\Models\SigningSession;
use App\Domain\Signing\Sessions\SigningToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * "GET is harmless", proven by doing every one of them twice.
 *
 * AGENTS.md: "Signing pages never apply signatures, consume one-shot tokens, or advance
 * recipients on GET. Mail scanners and previews must be safe." That is not a style rule. A
 * recipient's mail provider fetches every URL in a message before the recipient sees it, link
 * expanders follow them, archivers store them, and browsers prefetch on hover — so any GET
 * with a side effect is a signature applied by a robot.
 *
 * The snapshot below covers the agreement and its credentials: the envelope, every recipient,
 * every field value, every attestation, every invitation, every OTP challenge, and the outbox.
 * Two fetches of every GET route change none of it.
 *
 * ## The one thing that does change
 *
 * A signing session's `expires_at` and `last_seen_at` slide on each authorized request, which
 * is a deliberate exception and is why the snapshot names the session columns it compares
 * rather than taking the row whole. It changes when a browser tab stops working and nothing
 * about the agreement — the envelope, the recipient, the values, and the attestations are all
 * inside the snapshot and all identical afterwards. A fixed window would instead log out the
 * person reading a long contract carefully, who is the last person this product should
 * interrupt.
 */
class HarmlessGetTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_the_route_file_declares_only_reads_as_gets(): void
    {
        $gets = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'signing.'))
            ->filter(static fn ($route): bool => in_array('GET', $route->methods(), true))
            ->map(static fn ($route): string => (string) $route->getName())
            ->values()
            ->sort()
            ->values()
            ->all();

        // Pinned, so adding a GET to the signing surface forces somebody to come here and
        // add it to the fetch-twice test below rather than shipping it unexercised.
        $this->assertSame([
            'signing.landing',
            'signing.legacy.show',
            'signing.session.document',
            'signing.session.show',
        ], $gets);
    }

    public function test_every_get_leaves_the_agreement_exactly_as_it_was(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        // A live invitation that nothing below may consume, and a live session so the
        // session-scoped GETs are actually reached rather than 403ing before the controller.
        $issued = $scenario->invite();
        $landing = GuestSigningScenario::landingUrl($issued);

        $sessionIssued = $scenario->invite();
        $cookie = $this->startSigningSession($scenario, $sessionIssued);

        $urls = [
            $landing,
            GuestSigningScenario::sessionUrl($scenario->envelope),
            GuestSigningScenario::sessionUrl($scenario->envelope, '/document'),
            '/signing/'.$recipient->public_id,
            // The refusal paths are GETs too, and a 403 that consumed something would be
            // worse than one that did not.
            '/sign/'.$scenario->envelope->public_id.'/'.SigningToken::generate(),
        ];

        $before = $this->snapshot();

        foreach ($urls as $url) {
            $this->asSigner($cookie)->get($url);
            $this->asSigner($cookie)->get($url);
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_fetching_the_landing_page_a_hundred_times_never_spends_the_credential(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::landingUrl($issued);

        for ($fetch = 0; $fetch < 100; $fetch++) {
            $this->get($url)->assertOk();
        }

        $this->assertNull($issued->invitation->refresh()->consumed_at);
        $this->assertSame(0, SigningSession::query()->count());
        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_no_get_ever_sends_mail(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $this->get(GuestSigningScenario::landingUrl($issued))->assertOk();
        $this->get('/signing/'.$scenario->recipient()->public_id)->assertOk();

        $this->assertSame(0, OutboundMail::query()->count());
        $this->assertSame(0, SigningOtpChallenge::query()->count());
    }

    /**
     * Everything about the agreement that a GET must not touch.
     *
     * Whole rows rather than chosen columns, so a new column added to any of these tables is
     * covered by this test the day it exists.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'envelopes' => Envelope::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'recipients' => EnvelopeRecipient::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'values' => EnvelopeFieldValue::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'attestations' => RecipientAttestation::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'invitations' => RecipientInvitation::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'otp_challenges' => SigningOtpChallenge::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'mail' => OutboundMail::query()->orderBy('id')->get()->map->getAttributes()->all(),
            // Sessions are compared by the columns that describe *scope*, not lifetime: the
            // sliding expiry is the documented exception. A new session appearing, or an
            // existing one changing which envelope or recipient it covers, still fails here.
            'sessions' => SigningSession::query()->orderBy('id')->get()->map(
                static fn (SigningSession $session): array => [
                    'public_id' => $session->public_id,
                    'envelope_id' => $session->envelope_id,
                    'recipient_id' => $session->recipient_id,
                    'invitation_id' => $session->invitation_id,
                    'verification_method' => $session->verification_method->value,
                    'ended_at' => $session->ended_at?->toIso8601String(),
                    'return_url' => $session->return_url,
                ],
            )->all(),
        ];
    }
}
