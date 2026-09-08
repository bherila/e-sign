<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use App\Domain\Signing\Sessions\Models\SigningOtpChallenge;
use App\Domain\Signing\Sessions\Models\SigningSession;
use App\Domain\Signing\Sessions\OtpPurpose;
use App\Domain\Signing\Sessions\SigningVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\Support\ReadsMailedOtpCodes;
use Tests\TestCase;

/**
 * `/signing/{recipientPublicId}` — the compatibility URL, and the only way a bare recipient
 * identifier ever leads to signing.
 *
 * docs/HANDOFF.md section 8 refuses possession of an identifier as authorization and names
 * the one acceptable substitute: "A legacy `/signing/{recipientId}` resolver can require
 * mailbox verification." Everything here is about holding that line — the identifier reaches
 * a form and nothing more, and the responses are the same whether the stated address is right
 * or wrong.
 */
class LegacyRecipientResolverTest extends TestCase
{
    use InteractsWithSigningSession;
    use ReadsMailedOtpCodes;
    use RefreshDatabase;

    public function test_the_identifier_reaches_a_form_that_reveals_nothing(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $response = $this->get('/signing/'.$recipient->public_id);

        $response->assertOk();
        $response->assertSee('Confirm your email address');

        // Anybody holding the identifier can reach this page, so it must not say who is
        // contracting with whom, or what about.
        $response->assertDontSee($scenario->envelope->title);
        $response->assertDontSee($recipient->name);
        $response->assertDontSee($recipient->email);
        $response->assertDontSee($scenario->signing->workspace->name);
    }

    public function test_the_right_address_gets_a_code(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $response = $this->post('/signing/'.$recipient->public_id, ['email' => $recipient->email]);

        $response->assertOk();
        $response->assertSee('If that address matches');
        $response->assertDontSee($recipient->email);

        $challenge = SigningOtpChallenge::query()->firstOrFail();
        $this->assertSame(OtpPurpose::LegacyResolve, $challenge->purpose);
        $this->assertSame(1, OutboundMail::query()->where('kind', MailKind::Otp->value)->count());
    }

    public function test_a_wrong_address_produces_the_same_page_and_no_code(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $right = $this->post('/signing/'.$recipient->public_id, ['email' => $recipient->email]);
        $wrong = $this->post('/signing/'.$recipient->public_id, ['email' => 'someone.else@example.test']);

        $right->assertOk();
        $wrong->assertOk();
        $this->assertSame($right->getContent(), $wrong->getContent());

        // Only one code went out, and it went to the address on file.
        $this->assertSame(1, OutboundMail::query()->where('kind', MailKind::Otp->value)->count());
        $this->assertSame(
            $recipient->email,
            OutboundMail::query()->where('kind', MailKind::Otp->value)->firstOrFail()->to_email,
        );
    }

    public function test_the_address_comparison_is_case_insensitive(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $this->post('/signing/'.$recipient->public_id, ['email' => strtoupper($recipient->email)])
            ->assertOk();

        $this->assertSame(1, SigningOtpChallenge::query()->count());
    }

    public function test_the_right_code_mints_a_fresh_invitation_and_a_session(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $this->post('/signing/'.$recipient->public_id, ['email' => $recipient->email])->assertOk();

        $response = $this->post('/signing/'.$recipient->public_id.'/verify', [
            'code' => $this->mailedCode(),
        ]);

        $response->assertRedirect(GuestSigningScenario::sessionUrl($scenario->envelope));

        $session = SigningSession::query()->firstOrFail();
        $this->assertSame(SigningVerification::LinkOtp, $session->verification_method);
        $this->assertNotNull($session->invitation_id, 'The credential chain stays readable.');

        // The invitation exists and is already spent: it was minted and exchanged inside the
        // one request, so the plaintext never reached a page, a redirect, or a log.
        $invitation = RecipientInvitation::query()->findOrFail($session->invitation_id);
        $this->assertNotNull($invitation->consumed_at);

        // And the guest can actually sign from here.
        $cookie = $this->signingCookieValue($response);
        $this->asSigner($cookie)
            ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
            ->assertOk()
            ->assertSee($scenario->envelope->title);
    }

    public function test_a_wrong_code_and_a_code_that_was_never_issued_look_identical(): void
    {
        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        // No code was ever requested for this recipient.
        $never = $this->post('/signing/'.$recipient->public_id.'/verify', ['code' => '123456']);

        $this->post('/signing/'.$recipient->public_id, ['email' => $recipient->email])->assertOk();
        $wrong = $this->post('/signing/'.$recipient->public_id.'/verify', [
            'code' => $this->mailedCode() === '000000' ? '111111' : '000000',
        ]);

        $never->assertStatus(422);
        $wrong->assertStatus(422);
        $this->assertSame($never->getContent(), $wrong->getContent());
        $this->assertSame(0, SigningSession::query()->count());
    }

    public function test_a_code_issued_for_the_legacy_flow_cannot_start_a_link_session(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $this->post('/signing/'.$recipient->public_id, ['email' => $recipient->email])->assertOk();
        $legacyCode = $this->mailedCode();

        $issued = $scenario->invite();
        $startUrl = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        // The legacy code stands in for a credential the caller does not have; the
        // session-start code is an extra check on top of one they do. Interchangeable codes
        // would make the weaker of the two the effective bar for both.
        $this->post($startUrl, ['code' => $legacyCode])->assertStatus(422);
        $this->assertSame(0, SigningSession::query()->count());
    }

    public function test_the_resolver_is_rate_limited_per_client_address(): void
    {
        config()->set('esign.signing.otp.per_ip_per_hour', 2);

        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $this->post('/signing/'.$recipient->public_id, ['email' => 'wrong@example.test'])->assertOk();
        $this->post('/signing/'.$recipient->public_id, ['email' => 'wrong@example.test'])->assertOk();

        $response = $this->post('/signing/'.$recipient->public_id, ['email' => 'wrong@example.test']);
        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    /**
     * docs/security/review-2026-09.md finding G-1.
     *
     * The per-destination-address issuance ceiling can only be reached by an address that
     * matched, so surfacing it as a 429 made this endpoint the address oracle its entire
     * design exists to prevent: post a guessed address until the ceiling, and a 429 means
     * the guess was right while a 200 means it was wrong. The ceiling is still enforced —
     * no challenge, no mail — and the page is the same either way.
     */
    public function test_exhausting_the_per_address_ceiling_does_not_confirm_the_address(): void
    {
        config()->set('esign.signing.otp.per_address_per_hour', 2);
        // High enough that the per-client ceiling, which is reported and should be, is not
        // what either sequence below trips.
        config()->set('esign.signing.otp.per_ip_per_hour', 100);

        $scenario = GuestSigningScenario::sent();
        $recipient = $scenario->recipient();

        $right = $this->probe($recipient->public_id, $recipient->email, 3);
        $wrong = $this->probe($recipient->public_id, 'not.the.signer@example.test', 3);

        $this->assertSame($wrong, $right, 'A correct address must not be distinguishable by status.');
        $this->assertSame([200, 200, 200], $right);

        // The ceiling itself still bites: two codes went out, not three.
        $this->assertSame(2, SigningOtpChallenge::query()->count());
        $this->assertSame(2, OutboundMail::query()->where('kind', MailKind::Otp->value)->count());
    }

    /**
     * @return list<int>
     */
    private function probe(string $recipientPublicId, string $email, int $times): array
    {
        $statuses = [];

        for ($i = 0; $i < $times; $i++) {
            $statuses[] = $this->post('/signing/'.$recipientPublicId, ['email' => $email])->getStatusCode();
        }

        return $statuses;
    }

    public function test_a_recipient_who_cannot_sign_gets_no_code(): void
    {
        $scenario = GuestSigningScenario::sent();
        $later = $scenario->recipient('seller');

        $this->post('/signing/'.$later->public_id, ['email' => $later->email])->assertOk();

        $this->assertSame(0, SigningOtpChallenge::query()->count());
        $this->assertSame(0, OutboundMail::query()->count());
    }

    public function test_an_unknown_recipient_identifier_is_a_404(): void
    {
        $this->get('/signing/'.Str::ulid())->assertNotFound();
    }

    private function mailedCode(): string
    {
        return $this->mailedOtpCode();
    }
}
