<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * The session cookie itself.
 *
 * Single sign-on here is a redirect to a provider, never a session cookie shared with one.
 * The attributes below are what keeps that true: a host-only cookie under a name of this
 * application's own cannot be presented to, or set by, anything else on the parent domain
 * (docs/HANDOFF.md section 5).
 *
 * These are asserted against a real login response rather than against `config()`, because
 * the thing that matters is the `Set-Cookie` header a browser actually receives.
 */
class SessionCookieTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'local'];

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_the_login_response_sets_a_host_only_locked_down_session_cookie(): void
    {
        // The suite runs on the array driver, which issues no cookie at all. A cookie is
        // what is under test, so this one request uses a persistent driver.
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertRedirect(route('dashboard'));

        $cookie = $this->sessionCookie($response->headers->getCookies());

        $this->assertNotNull($cookie, 'The login response must set a session cookie.');
        $this->assertSame('esign_session', $cookie->getName());
        // Host-only: no Domain attribute, so the browser never offers it to a sibling host.
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly(), 'The session cookie must not be readable from JavaScript.');
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertFalse($cookie->isRaw());
    }

    public function test_the_session_cookie_is_marked_secure_when_configured(): void
    {
        config(['session.driver' => 'database', 'session.secure' => true]);

        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertTrue($this->sessionCookie($response->headers->getCookies())?->isSecure());
    }

    public function test_secure_is_the_default_in_production_and_not_elsewhere(): void
    {
        // Asserted against the configuration file itself: the point of the default is that a
        // production deployment gets Secure without anyone remembering to ask for it, which
        // a test that sets the value cannot show.
        $this->assertTrue($this->sessionConfigFor('production')['secure']);
        $this->assertFalse($this->sessionConfigFor('local')['secure']);
    }

    public function test_the_cookie_is_named_and_scoped_by_configuration_not_by_the_application_name(): void
    {
        $config = $this->sessionConfigFor('production');

        $this->assertSame('esign_session', $config['cookie']);
        // Null, not the parent domain. Nothing about single sign-on requires a shared cookie.
        $this->assertNull($config['domain']);
        $this->assertSame('lax', $config['same_site']);
        $this->assertTrue($config['http_only']);
    }

    /**
     * Evaluate config/session.php as it would be evaluated on a deployment in `$environment`.
     *
     * @return array<string, mixed>
     */
    private function sessionConfigFor(string $environment): array
    {
        $previous = $_ENV['APP_ENV'] ?? null;

        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);

        try {
            /** @var array<string, mixed> $config */
            $config = require base_path('config/session.php');

            return $config;
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
                putenv('APP_ENV');
            } else {
                $_ENV['APP_ENV'] = $previous;
                $_SERVER['APP_ENV'] = $previous;
                putenv('APP_ENV='.$previous);
            }
        }
    }

    /**
     * @param  list<Cookie>  $cookies
     */
    private function sessionCookie(array $cookies): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        return null;
    }
}
