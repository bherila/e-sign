<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers every response carries, signing pages included.
 *
 * docs/HANDOFF.md section 8 asks for `no-referrer` and no third-party origin on the signing
 * surface, and {@see SigningSecurityHeaders} has always answered that. Nothing answered it
 * anywhere else. Until this middleware existed, `/dashboard`, `/login`, the document and
 * template routes, the field editor, `/health/ready` and `/up` carried no
 * `X-Frame-Options`, no `Referrer-Policy`, no `X-Content-Type-Options`, no
 * `Permissions-Policy`, and — because the global CSP was configured against an API the
 * installed version of `spatie/laravel-csp` no longer has — no `frame-ancestors` either. An
 * authenticated workspace page that publishes templates and saves field positions was
 * framable from any origin (docs/security/review-2026-09.md finding X-1/X-2).
 *
 * The Docker profile's nginx sets three of these at the server level, which is why the gap
 * was easy to miss. The cPanel profile has no such layer, and `htaccess-append.txt` sets no
 * headers at all — so on the shared-hosting target the application was the only thing that
 * could have set them, and it did not.
 *
 * ## Nothing here overwrites a header a route already set
 *
 * This runs in the *global* stack, so it sees a response after route middleware has finished
 * with it. Every header is written only when absent, which makes {@see SigningSecurityHeaders}
 * authoritative on its own surface: its `Referrer-Policy`, its `Cache-Control: private,
 * no-store`, and above all its narrower CSP survive intact. A guest signing page is not
 * supposed to get the application's ordinary policy, and this is how that stays true without
 * either middleware knowing about the other.
 *
 * ## Strict-Transport-Security
 *
 * Emitted only on a request that arrived over TLS — sending it over plaintext is meaningless
 * (a browser ignores it) and pinning a development host to HTTPS for a year is a real
 * nuisance. `includeSubDomains` is deliberately **not** included: a deployment at an apex
 * domain would otherwise commit every sibling subdomain, including the identity provider's,
 * to HTTPS on this application's say-so. An operator who wants it configures it at the proxy,
 * where the blast radius is visible.
 *
 * Set `ESIGN_HSTS_MAX_AGE=0` to switch it off where a proxy already owns the header.
 */
class SecurityHeaders
{
    /** One year, the value a preload list would require and a sane floor besides. */
    public const DEFAULT_MAX_AGE = 31_536_000;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $this->setIfAbsent($response, 'Referrer-Policy', 'no-referrer');
        $this->setIfAbsent($response, 'X-Frame-Options', 'DENY');
        $this->setIfAbsent($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfAbsent(
            $response,
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
        );

        $maxAge = $this->maxAge();

        if ($maxAge > 0 && $request->isSecure()) {
            $this->setIfAbsent($response, 'Strict-Transport-Security', 'max-age='.$maxAge);
        }

        return $response;
    }

    private function setIfAbsent(Response $response, string $header, string $value): void
    {
        if (! $response->headers->has($header)) {
            $response->headers->set($header, $value);
        }
    }

    private function maxAge(): int
    {
        return max(0, (int) config('esign.security.hsts_max_age', self::DEFAULT_MAX_AGE));
    }
}
