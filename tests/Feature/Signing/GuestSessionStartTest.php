<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Sessions\InvitationIssuer;
use App\Domain\Signing\Sessions\Models\SigningSession;
use App\Domain\Signing\Sessions\SigningCookie;
use App\Domain\Signing\Sessions\SigningToken;
use App\Domain\Signing\Sessions\SigningVerification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * Turning a credential into a session, and the cookie that carries it.
 *
 * The landing page and the Continue button exist as two separate requests for one reason,
 * and the first test here is that reason: a mail-security scanner fetches every URL in a
 * message before the recipient does, and AGENTS.md requires that to be harmless.
 */
class GuestSessionStartTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_the_landing_page_shows_the_agreement_without_consuming_anything(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $response = $this->get(GuestSigningScenario::landingUrl($issued));

        $response->assertOk();
        $response->assertSee($scenario->envelope->title);
        $response->assertSee($scenario->recipient()->name);
        $response->assertSee($scenario->signing->workspace->name);
        $response->assertCookieMissing(SigningCookie::NAME);

        $this->assertNull($issued->invitation->refresh()->consumed_at);
        $this->assertSame(0, SigningSession::query()->count());
    }

    public function test_starting_a_session_consumes_the_invitation_and_sets_the_cookie(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        $response = $this->post(GuestSigningScenario::startUrl($scenario->envelope, $token));

        $response->assertRedirect(GuestSigningScenario::sessionUrl($scenario->envelope));
        $this->assertSame(303, $response->getStatusCode(), 'A POST redirect must be followed with GET.');

        $this->assertNotNull($issued->invitation->refresh()->consumed_at);

        $session = SigningSession::query()->firstOrFail();
        $this->assertSame($scenario->envelope->getKey(), $session->envelope_id);
        $this->assertSame($scenario->recipient()->getKey(), $session->recipient_id);
        $this->assertSame($issued->invitation->getKey(), $session->invitation_id);
        $this->assertSame(SigningVerification::Link, $session->verification_method);
        $this->assertTrue($session->isLive());
    }

    public function test_the_cookie_is_host_only_http_only_lax_and_scoped_to_the_signing_path(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $response = $this->post(
            GuestSigningScenario::startUrl($scenario->envelope, GuestSigningScenario::tokenOf($issued)),
        );

        $cookie = $response->getCookie(SigningCookie::NAME, false);

        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain(), 'A signing cookie is host-only.');
        $this->assertSame(SigningCookie::PATH, $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(0, $cookie->getExpiresTime(), 'A session cookie does not outlive the browser.');
        $this->assertNotSame(SigningCookie::NAME, $cookie->getName().'x');
    }

    public function test_the_cookie_value_is_not_the_stored_verifier(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);
        $session = SigningSession::query()->firstOrFail();

        $this->assertNotSame($session->session_token_hash, $cookie);
        $this->assertNotSame($session->public_id, $cookie);
    }

    public function test_the_cookie_is_secure_in_production(): void
    {
        // The attribute is decided by the environment rather than by a request, so this asks
        // the class the question directly instead of booting a second application.
        $cookies = app(SigningCookie::class);
        $this->assertFalse($cookies->isSecure(), 'Local development over plain HTTP has to work.');

        config()->set('session.secure', true);
        $this->assertTrue(
            app(SigningCookie::class)->isSecure(),
            'A deployment that has decided HTTPS-only for its own session cookie should not have to decide twice.',
        );
    }

    public function test_a_second_start_with_the_same_token_is_refused(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);
        $url = GuestSigningScenario::startUrl($scenario->envelope, $token);

        $this->post($url)->assertRedirect();
        $this->post($url)->assertForbidden();

        $this->assertSame(1, SigningSession::query()->count());
    }

    public function test_a_revoked_invitation_cannot_start_a_session(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        app(InvitationIssuer::class)->revokeAllFor($scenario->recipient(), 'sender_withdrew');

        $this->get(GuestSigningScenario::landingUrl($issued))->assertForbidden();
        $this->post(GuestSigningScenario::startUrl($scenario->envelope, $token))->assertForbidden();
        $this->assertSame(0, SigningSession::query()->count());
    }

    public function test_an_expired_invitation_cannot_start_a_session(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite(ttlHours: 1);
        $token = GuestSigningScenario::tokenOf($issued);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(3));

        try {
            $this->post(GuestSigningScenario::startUrl($scenario->envelope, $token))->assertForbidden();
            $this->assertSame(0, SigningSession::query()->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_malformed_token_is_a_404_at_the_router(): void
    {
        $scenario = GuestSigningScenario::sent();

        $this->get('/sign/'.$scenario->envelope->public_id.'/short')->assertNotFound();
        $this->get('/sign/'.$scenario->envelope->public_id.'/'.str_repeat('a', 60))->assertNotFound();
    }

    public function test_a_well_formed_but_unknown_token_is_refused_without_saying_why(): void
    {
        $scenario = GuestSigningScenario::sent();
        $invented = SigningToken::generate();

        $response = $this->get('/sign/'.$scenario->envelope->public_id.'/'.$invented);

        $response->assertForbidden();
        $response->assertSee('This link cannot be used');
    }

    public function test_every_refusal_renders_the_same_visible_page(): void
    {
        $scenario = GuestSigningScenario::sent();

        $unknown = $this->get('/sign/'.$scenario->envelope->public_id.'/'.SigningToken::generate());

        $issued = $scenario->invite();
        app(InvitationIssuer::class)->revokeAllFor($scenario->recipient(), 'sender_withdrew');
        $revoked = $this->get(GuestSigningScenario::landingUrl($issued));

        $unknown->assertForbidden();
        $revoked->assertForbidden();

        // Identical once the operator-only HTML comment is removed. Distinguishing the two on
        // screen would tell a prober which tokens once existed.
        $this->assertSame(
            $this->withoutReasonComment($unknown->getContent()),
            $this->withoutReasonComment($revoked->getContent()),
        );
    }

    private function withoutReasonComment(string|false $html): string
    {
        return (string) preg_replace('/<!--\s*reason:.*?-->/s', '', (string) $html);
    }

    public function test_the_session_expiry_slides_on_each_authorized_request(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01T10:00:00Z'));

        try {
            $scenario = GuestSigningScenario::sent();
            $cookie = $this->startSigningSession($scenario);

            $first = SigningSession::query()->firstOrFail()->expires_at;

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01T11:00:00Z'));
            $this->asSigner($cookie)
                ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
                ->assertOk();

            $this->assertTrue(SigningSession::query()->firstOrFail()->expires_at->greaterThan($first));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_an_expired_session_is_refused_rather_than_renewed(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(4));

        try {
            $this->asSigner($cookie)
                ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
                ->assertForbidden();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_start_is_rate_limited_per_client_address(): void
    {
        config()->set('esign.signing.start_per_ip_per_hour', 3);

        $scenario = GuestSigningScenario::sent();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(GuestSigningScenario::startUrl($scenario->envelope, SigningToken::generate()))
                ->assertForbidden();
        }

        // The fourth attempt is refused for a different reason: the ceiling, not the token.
        $response = $this->post(
            GuestSigningScenario::startUrl($scenario->envelope, SigningToken::generate()),
        );

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    public function test_a_return_destination_on_the_allowlist_is_kept(): void
    {
        config()->set('esign.signing.return_url_allowlist', ['consumer.example.test']);

        $scenario = GuestSigningScenario::sent();
        $this->startSigningSession($scenario, data: ['return' => 'https://consumer.example.test/done?ref=7']);

        $this->assertSame(
            'https://consumer.example.test/done?ref=7',
            SigningSession::query()->firstOrFail()->return_url,
        );
    }

    public function test_a_return_destination_off_the_allowlist_is_ignored_not_rejected(): void
    {
        config()->set('esign.signing.return_url_allowlist', ['consumer.example.test']);

        $scenario = GuestSigningScenario::sent();
        $this->startSigningSession($scenario, data: ['return' => 'https://attacker.example.test/steal']);

        $this->assertNull(
            SigningSession::query()->firstOrFail()->return_url,
            'An unlisted destination is dropped silently; erroring would make it a probe.',
        );
    }

    public function test_no_return_destination_is_honoured_when_the_allowlist_is_empty(): void
    {
        config()->set('esign.signing.return_url_allowlist', []);

        $scenario = GuestSigningScenario::sent();
        $this->startSigningSession($scenario, data: ['return' => 'https://consumer.example.test/done']);

        $this->assertNull(SigningSession::query()->firstOrFail()->return_url);
    }
}
