<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use Illuminate\Contracts\Foundation\Application;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The one cookie a guest signing session travels in, and every attribute it carries.
 *
 * Separate from `esign_session` deliberately. The application session belongs to a signed-in
 * workspace member; this one belongs to somebody with no account, scoped to one envelope,
 * for a couple of hours. Sharing a cookie would mean a guest's credential and an
 * administrator's credential had the same name, the same lifetime, and the same blast
 * radius, and that signing out of one would sign out of the other.
 *
 * | Attribute | Value | Why |
 * |---|---|---|
 * | `Domain` | unset | Host-only. A cookie scoped to the registrable domain would be sent to every subdomain, including ones this application does not run on. |
 * | `Path` | `/sign` | The only routes that need it. `/signing/...`, the legacy resolver, does not match — path matching requires a `/` boundary — so the compatibility surface never sees a session credential it has no use for. |
 * | `HttpOnly` | true | A signing page runs JavaScript; the credential is not JavaScript's business. |
 * | `SameSite` | `Lax` | The invitation arrives as a link in a mail client, which is a cross-site top-level GET. `Strict` would drop the cookie on exactly that navigation and send every returning signer back to the landing page. `None` would ship it on cross-site subrequests, which nothing here needs. |
 * | `Secure` | production, or whenever the session config says so | A credential must not cross plaintext HTTP in production; local development over `http://localhost` still has to work. |
 * | `Expires` | session cookie | Closing the browser drops it. The server-side row is the real authority and expires on its own clock; the cookie deliberately does not outlive the window it was created in. |
 *
 * The value is the plaintext session token. Laravel's `EncryptCookies` wraps it on the way
 * out and unwraps it on the way in, which binds the cookie to its own name and stops it
 * being replayed under another; the server still only ever stores the SHA-256 verifier, so
 * decrypting the cookie jar of a stolen laptop is the only way to obtain one.
 */
final class SigningCookie
{
    public const NAME = 'esign_signing';

    public const PATH = '/sign';

    public function __construct(private readonly Application $app) {}

    public function issue(#[SensitiveParameter] string $token): Cookie
    {
        return $this->cookie($token, 0);
    }

    /** An immediately expiring cookie of exactly the same shape, so the browser drops it. */
    public function forget(): Cookie
    {
        return $this->cookie('', time() - 3_600);
    }

    /**
     * Secure in production, and wherever the application session is already secure.
     *
     * Reading `session.secure` as well as the environment means a deployment that has
     * decided HTTPS-only for its own session cookie does not have to decide it twice.
     */
    public function isSecure(): bool
    {
        return $this->app->environment('production')
            || (bool) $this->app->make('config')->get('session.secure', false);
    }

    private function cookie(#[SensitiveParameter] string $value, int $expiresAt): Cookie
    {
        return new Cookie(
            name: self::NAME,
            value: $value,
            expire: $expiresAt,
            path: self::PATH,
            domain: null,
            secure: $this->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
