<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Sessions\SigningToken;
use App\Http\Middleware\SigningSecurityHeaders;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\GuestSigningScenario;
use Tests\Support\InteractsWithSigningSession;
use Tests\TestCase;

/**
 * The headers, the CSRF stack, and the promise that a credential never reaches a log.
 *
 * AGENTS.md names two of these for this surface specifically — `Referrer-Policy: no-referrer`
 * and no third-party CDN or analytics — and docs/HANDOFF.md section 8 adds the third: "Scrub
 * tokens from logs". The referrer rule is the one with teeth here, because the invitation
 * credential travels in the URL path: without it, every outbound request the page made would
 * hand the credential to whoever it was made to.
 */
class SigningSecurityHeadersTest extends TestCase
{
    use InteractsWithSigningSession;
    use RefreshDatabase;

    public function test_every_signing_response_carries_the_signing_headers(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $sessionIssued = $scenario->invite();
        $cookie = $this->startSigningSession($scenario, $sessionIssued);

        $responses = [
            'landing' => $this->get(GuestSigningScenario::landingUrl($issued)),
            'session' => $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope)),
            'document' => $this->asSigner($cookie)
                ->get(GuestSigningScenario::sessionUrl($scenario->envelope, '/document')),
            'legacy' => $this->get('/signing/'.$scenario->recipient()->public_id),
            'refusal' => $this->get(
                '/sign/'.$scenario->envelope->public_id.'/'
                .SigningToken::generate(),
            ),
        ];

        foreach ($responses as $name => $response) {
            $this->assertHeaders($name, $response);
        }
    }

    public function test_the_policy_admits_no_third_party_origin(): void
    {
        $policy = (new SigningSecurityHeaders)->policy();

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);

        // The application's global policy names an analytics host. It must never reach this
        // surface, and it does not: route middleware sets the header first, and Spatie's
        // AddCspHeaders returns early when one is already present.
        $this->assertStringNotContainsString('cloudflareinsights', $policy);
        $this->assertStringNotContainsString('http://', $policy);
        $this->assertStringNotContainsString('https://', $policy);
    }

    public function test_the_global_analytics_policy_does_not_reach_a_signing_page(): void
    {
        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $response = $this->get(GuestSigningScenario::landingUrl($issued));

        $this->assertSame(
            (new SigningSecurityHeaders)->policy(),
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_the_signing_page_loads_nothing_from_another_origin(): void
    {
        $scenario = GuestSigningScenario::sent();
        $cookie = $this->startSigningSession($scenario);

        $html = (string) $this->asSigner($cookie)
            ->get(GuestSigningScenario::sessionUrl($scenario->envelope))
            ->getContent();

        // Every `src` and `href` the page emits is same-origin. A CDN font, an analytics
        // beacon, or a hosted PDF.js worker would fail here before it failed the CSP.
        preg_match_all('/(?:src|href)="([^"]+)"/i', $html, $matches);

        foreach ($matches[1] as $url) {
            $this->assertFalse(
                str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
                    ? parse_url($url, PHP_URL_HOST) !== parse_url((string) config('app.url'), PHP_URL_HOST)
                    : false,
                "The signing page referenced a third-party origin: {$url}",
            );
        }
    }

    public function test_every_signing_post_is_csrf_protected(): void
    {
        /** @var Router $router */
        $router = app(Router::class);

        $posts = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with((string) $route->getName(), 'signing.'))
            ->filter(static fn ($route): bool => in_array('POST', $route->methods(), true));

        $this->assertGreaterThanOrEqual(5, $posts->count());

        foreach ($posts as $route) {
            $this->assertContains(
                PreventRequestForgery::class,
                $router->gatherRouteMiddleware($route),
                $route->getName().' must be CSRF protected.',
            );
        }
    }

    public function test_the_csrf_middleware_refuses_a_post_with_no_token(): void
    {
        // Laravel's middleware short-circuits while a test suite is running
        // (`PreventRequestForgery::runningUnitTests()`), so a 419 is something this harness
        // can never produce through the HTTP layer. The subclass turns that one escape hatch
        // off and leaves every other line of the real class in place; together with the
        // structural test above — every signing POST carries this middleware — that is the
        // whole claim.
        $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $request = Request::create('/sign/x/session/accept', 'POST');
        $request->setLaravelSession(app('session.store'));

        $this->expectException(TokenMismatchException::class);

        $middleware->handle($request, static fn () => response('never reached'));
    }

    public function test_no_credential_is_ever_written_to_a_log(): void
    {
        Log::spy();

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();
        $token = GuestSigningScenario::tokenOf($issued);

        $this->get(GuestSigningScenario::landingUrl($issued))->assertOk();
        $cookie = $this->startSigningSession($scenario, $scenario->invite());
        $this->asSigner($cookie)->get(GuestSigningScenario::sessionUrl($scenario->envelope))->assertOk();
        $this->get('/sign/'.$scenario->envelope->public_id.'/'.$token)->assertForbidden();

        // Nothing at all was logged, which is the strongest form of "the token was not". A
        // future change that starts logging on this path fails here and has to prove what it
        // writes rather than being taken on trust.
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    private function assertHeaders(string $name, TestResponse $response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        // Symfony normalises the directive order; the pair is what matters.
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Content-Security-Policy');

        $this->assertStringContainsString(
            'camera=()',
            (string) $response->headers->get('Permissions-Policy'),
            "The {$name} response should not be able to ask for a camera.",
        );
    }
}
