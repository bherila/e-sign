<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use BWH\Auth\Http\Middleware\RequireActiveProviderSession;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every session-authenticated person route carries RequireActiveProviderSession, and nothing
 * that is not a provider identity does (docs/operations/provider-identity.md).
 *
 * A route added behind `auth` without `provider-session` would keep a session the identity
 * provider has ended, the moment enforcement is turned on; this fails it. Run with every
 * optional route family registered (SSO mode with the local families forced on, and the
 * package's audit routes), and again in standalone mode by the subclass.
 */
class ProviderSessionRouteCensusTest extends TestCase
{
    protected array $envOverrides = [
        'ESIGN_AUTH_MODE' => 'sso',
        'ESIGN_LOCAL_AUTH_ROUTES' => 'on',
        'BHERILA_AUTH_AUDIT_ROUTES' => 'true',
    ];

    /** Route names that must be checked, so the census cannot pass by finding nothing. */
    private const REQUIRED = [
        'dashboard',
        'documents.show',
        'documents.store',
        'members.index',
        'invitations.accept',
        'invitations.redeem',
        'templates.index',
        'editor.show',
    ];

    public function test_every_session_authenticated_route_checks_the_provider_session(): void
    {
        $unchecked = [];
        $checkedNames = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $this->authenticatesASession($route)) {
                continue;
            }
            if (! $this->checksProviderSession($route)) {
                $unchecked[] = implode('|', $route->methods()).' '.$route->uri();

                continue;
            }
            if ($route->getName() !== null) {
                $checkedNames[] = $route->getName();
            }
        }

        $this->assertSame([], $unchecked, "Add 'provider-session' after 'auth' on these routes.");
        $this->assertSame([], array_values(array_diff(self::REQUIRED, $checkedNames)));
    }

    public function test_no_guest_machine_or_provider_surface_checks_the_provider_session(): void
    {
        $offenders = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $guestOrMachine = str_starts_with((string) $route->getName(), 'signing.')
                || $route->getName() === 'invitations.show'
                || str_starts_with($route->uri(), 'webhooks/')
                || str_starts_with($route->uri(), 'health/')
                || str_starts_with($route->uri(), 'api/v1/')
                || $route->uri() === ltrim((string) config('bherila-auth.delegated_access.path'), '/')
                || in_array('service-credential', $middleware, true)
                || in_array('signed', $middleware, true);

            if ($guestOrMachine && $this->checksProviderSession($route)) {
                $offenders[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_signing_out_is_never_checked(): void
    {
        $logout = Route::getRoutes()->getByName('logout');

        $this->assertNotNull($logout);
        $this->assertFalse($this->checksProviderSession($logout));
        $this->assertContains('logout', (array) config('bherila-auth.provider_identity.except_routes'));
    }

    private function authenticatesASession(RouteDefinition $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || $middleware === 'auth:web'
                || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    private function checksProviderSession(RouteDefinition $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'provider-session' || $middleware === RequireActiveProviderSession::class
                || (is_string($middleware) && str_starts_with($middleware, RequireActiveProviderSession::class.':'))
                || (is_string($middleware) && str_starts_with($middleware, 'provider-session:'))) {
                return true;
            }
        }

        return false;
    }
}
