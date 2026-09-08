<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The headers every response carries, and the policy the application actually emits.
 *
 * docs/security/review-2026-09.md findings X-1, X-2, and X-3. Until this test existed the
 * only assertions about response hardening were on the guest signing surface, and everything
 * else — `/login`, `/dashboard`, the document, template, and editor routes, `/health/ready`,
 * `/up` — carried no `X-Frame-Options`, no `Referrer-Policy`, no `X-Content-Type-Options`,
 * no `Permissions-Policy`, and a `Content-Security-Policy` with no `frame-ancestors`, because
 * `config/csp.php` was written against an API `spatie/laravel-csp` v3 does not read.
 *
 * The point of the CSP assertion here is that it reads the **emitted header** rather than the
 * configured intent. That is the whole lesson of X-1: a policy object nothing loads looks
 * exactly like a policy that works.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Non-API surfaces that must all be covered. A route added to any of these files without
     * headers now fails here rather than shipping.
     *
     * @return array<string, array{string, int}>
     */
    public static function nonApiRoutes(): array
    {
        return [
            'home' => ['/', 200],
            'login' => ['/login', 200],
            'liveness probe' => ['/up', 200],
            // 503 without a database and storage behind it, which is the point: a failure
            // response is a response and gets the same headers.
            'readiness probe' => ['/health/ready', 503],
            'a route that does not exist' => ['/no-such-page', 404],
        ];
    }

    #[DataProvider('nonApiRoutes')]
    public function test_every_non_api_response_carries_the_security_headers(string $uri, int $status): void
    {
        $response = $this->get($uri);

        $response->assertStatus($status);
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
    }

    public function test_an_authenticated_workspace_page_cannot_be_framed(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Owner->value,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_the_emitted_policy_is_the_configured_one_and_admits_no_third_party_origin(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $policy, 'Every ordinary page carries a policy.');

        foreach (["default-src 'self'", "script-src 'self'", "object-src 'none'", "base-uri 'none'", "frame-ancestors 'none'", "form-action 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }

        // No `https://` at all: the only origins in the policy are this one, plus the `data:`
        // and `blob:` schemes the editor and PDF.js need.
        $this->assertStringNotContainsString('https://', $policy);
        $this->assertStringNotContainsString('cloudflareinsights', $policy);

        // A nonce in `style-src` suppresses the `'unsafe-inline'` beside it, and React writes
        // element `style` attributes for every field overlay.
        $this->assertStringNotContainsString('nonce-', $policy);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
    }

    public function test_the_signing_surface_keeps_its_own_narrower_policy(): void
    {
        // The global middleware runs outside route middleware and must not overwrite what
        // SigningSecurityHeaders set. `no-store` is the tell: nothing global sets it.
        $response = $this->get('/signing/'.Str::ulid());

        $response->assertNotFound();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_strict_transport_security_is_sent_only_over_tls(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age='.SecurityHeaders::DEFAULT_MAX_AGE);
    }

    public function test_a_deployment_whose_proxy_owns_the_header_can_switch_it_off(): void
    {
        config()->set('esign.security.hsts_max_age', 0);

        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
