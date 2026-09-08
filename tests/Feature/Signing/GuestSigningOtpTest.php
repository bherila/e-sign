<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Signing\Sessions\Models\SigningOtpChallenge;
use App\Domain\Signing\Sessions\Models\SigningSession;
use App\Domain\Signing\Sessions\SigningCookie;
use App\Domain\Signing\Sessions\SigningVerification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * The optional mailbox code.
 *
 * What it is worth is stated honestly in `App\Domain\Signing\Sessions\OtpChallenges`:
 * continued access to the same mailbox the link went to, which is a second check on one
 * factor and not a second factor. What it is *for* is the forwarded invitation, and the first
 * test here is exactly that case — the link alone stops being enough.
 */
class GuestSigningOtpTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_no_code_is_asked_for_when_the_deployment_does_not_require_one(): void
    {
        config()->set('esign.signing.require_otp', false);

        $scenario = GuestSigningScenario::sent();
        $this->startSigningSession($scenario);

        $this->assertSame(0, SigningOtpChallenge::query()->count());
        $this->assertSame(
            SigningVerification::Link,
            SigningSession::query()->firstOrFail()->verification_method,
        );
    }

    public function test_the_first_submission_mails_a_code_instead_of_starting_a_session(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $response = $this->post(
            GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued)),
        );

        $response->assertOk();
        $response->assertSee('code');
        $response->assertCookieMissing(SigningCookie::NAME);

        $this->assertSame(0, SigningSession::query()->count());
        $this->assertNull($issued->invitation->refresh()->consumed_at, 'The credential is not spent yet.');

        $mail = OutboundMail::query()->where('kind', MailKind::Otp->value)->firstOrFail();
        $this->assertSame($scenario->recipient()->email, $mail->to_email);
    }

    public function test_the_right_code_starts_a_session_recorded_as_link_plus_otp(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();

        $response = $this->post($url, ['code' => $this->mailedCode()]);

        $response->assertRedirect(GuestSigningScenario::sessionUrl($scenario->envelope));

        $session = SigningSession::query()->firstOrFail();
        $this->assertSame(SigningVerification::LinkOtp, $session->verification_method);
        $this->assertNotNull($issued->invitation->refresh()->consumed_at);
    }

    public function test_a_wrong_code_is_refused_and_costs_an_attempt(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();
        $this->post($url, ['code' => $this->wrongCode()])->assertStatus(422);

        $this->assertSame(1, SigningOtpChallenge::query()->firstOrFail()->attempts);
        $this->assertSame(0, SigningSession::query()->count());
    }

    public function test_the_challenge_is_burned_once_the_attempt_ceiling_is_reached(): void
    {
        config()->set('esign.signing.require_otp', true);
        config()->set('esign.signing.otp.max_attempts', 2);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();
        $code = $this->mailedCode();

        $this->post($url, ['code' => $this->wrongCode()])->assertStatus(422);
        $this->post($url, ['code' => $this->wrongCode()])->assertStatus(422);

        $this->assertNotNull(SigningOtpChallenge::query()->firstOrFail()->burned_at);

        // Even the correct code no longer works: the challenge is gone, not merely paused.
        $this->post($url, ['code' => $code])->assertStatus(422);
        $this->assertSame(0, SigningSession::query()->count());

        AuditEvent::query()->where('action', 'signing.otp.exhausted')->firstOrFail();
    }

    public function test_an_expired_code_is_refused(): void
    {
        config()->set('esign.signing.require_otp', true);
        config()->set('esign.signing.otp.ttl_minutes', 10);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();
        $code = $this->mailedCode();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

        try {
            $this->post($url, ['code' => $code])->assertStatus(422);
            $this->assertSame(0, SigningSession::query()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_asking_for_another_code_burns_the_previous_one(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();
        $first = $this->mailedCode();

        $this->post($url)->assertOk();

        $this->assertSame(2, SigningOtpChallenge::query()->count());
        $this->post($url, ['code' => $first])->assertStatus(422);
    }

    public function test_issuance_is_rate_limited_per_destination_address(): void
    {
        config()->set('esign.signing.require_otp', true);
        config()->set('esign.signing.otp.per_address_per_hour', 2);
        config()->set('esign.signing.otp.per_ip_per_hour', 100);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();
        $this->post($url)->assertOk();

        $response = $this->post($url);
        $response->assertStatus(429);
        $response->assertHeader('Retry-After');

        $this->assertSame(2, OutboundMail::query()->where('kind', MailKind::Otp->value)->count());
    }

    public function test_issuance_is_rate_limited_per_client_address(): void
    {
        config()->set('esign.signing.require_otp', true);
        config()->set('esign.signing.otp.per_address_per_hour', 100);
        config()->set('esign.signing.otp.per_ip_per_hour', 1);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $url = GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued));

        $this->post($url)->assertOk();
        $this->post($url)->assertStatus(429);
    }

    public function test_the_code_never_reaches_the_audit_trail(): void
    {
        config()->set('esign.signing.require_otp', true);

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $this->post(
            GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued)),
        )->assertOk();

        $code = $this->mailedCode();
        $events = AuditEvent::query()->get()->map(
            static fn (AuditEvent $event): string => json_encode($event->payload, JSON_THROW_ON_ERROR),
        )->implode(' ');

        $this->assertStringNotContainsString($code, $events);
    }

    public function test_an_envelope_setting_overrides_the_deployment_default(): void
    {
        config()->set('esign.signing.require_otp', false);

        $scenario = GuestSigningScenario::sent();
        $scenario->envelope->forceFill(['require_otp' => true])->save();

        $issued = $scenario->invite();

        $this->post(
            GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued)),
        )->assertOk();

        $this->assertSame(1, SigningOtpChallenge::query()->count());
    }

    public function test_a_workspace_setting_applies_when_the_envelope_says_nothing(): void
    {
        config()->set('esign.signing.require_otp', false);

        $scenario = GuestSigningScenario::sent();
        $scenario->signing->workspace->forceFill(['require_otp' => true])->save();

        $issued = $scenario->invite();

        $this->post(
            GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued)),
        )->assertOk();

        $this->assertSame(1, SigningOtpChallenge::query()->count());
    }

    /**
     * The code as it was mailed.
     *
     * Read from the outbox row, which is where a live code genuinely is until the row is
     * pruned — the transactional outbox renders from a persisted context. The class docblock
     * on `OtpChallenges` and docs/signing/guest-access.md both say so plainly rather than
     * implying the code is a secret from the operator.
     */
    private function mailedCode(): string
    {
        $mail = OutboundMail::query()
            ->where('kind', MailKind::Otp->value)
            ->orderByDesc('id')
            ->firstOrFail();

        return (string) ($mail->context['otp_code'] ?? '');
    }

    /** A code of the right shape that is certainly not the one that was sent. */
    private function wrongCode(): string
    {
        return $this->mailedCode() === '000000' ? '111111' : '000000';
    }
}
