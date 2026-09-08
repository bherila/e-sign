<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Signing\Capture\SignatureImage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers every guest signing page carries, including the streamed PDF.
 *
 * AGENTS.md is specific about this surface: "Signing pages: no third-party CDNs or
 * analytics, `Referrer-Policy: no-referrer`, PDF.js served locally." docs/HANDOFF.md
 * section 8 adds the reason for the first of those — an invitation credential travels in the
 * URL path, so any request this page makes to another origin would hand that origin the
 * credential in a `Referer` header.
 *
 * | Header | Value | Why |
 * |---|---|---|
 * | `Referrer-Policy` | `no-referrer` | The URL contains a bearer credential. Nothing may carry it off this origin — not an outbound link, not an image, not a form. |
 * | `X-Frame-Options` | `DENY` | Belt to the CSP braces. `frame-ancestors` is the standard and is honoured by every current browser; this is what an old one obeys. |
 * | `Content-Security-Policy` | see below | Everything from this origin. There is no origin in it that this deployment does not serve. |
 * | `X-Content-Type-Options` | `nosniff` | A streamed PDF must be treated as a PDF. |
 * | `Cache-Control` | `private, no-store` | The page shows an unexecuted agreement and its field values to somebody who is often on a shared machine. |
 * | `Permissions-Policy` | camera, microphone, geolocation off | A signing page has no use for any of them, and a page that cannot ask cannot be tricked into asking. |
 *
 * ## The policy
 *
 * `default-src 'self'` with three narrowings that are all consequences of how the page
 * works rather than conveniences:
 *
 * - `img-src` adds `data:` and `blob:`. A captured signature *is* a `data:` URL — that is
 *   the whole shape of {@see SignatureImage} — and PDF.js paints
 *   page rasters through blob URLs.
 * - `worker-src` adds `blob:`. PDF.js runs its parser in a real worker, which it constructs
 *   from a blob; without this the library silently falls back to a "fake worker" that parses
 *   a multi-megabyte PDF on the UI thread.
 * - `style-src` adds `'unsafe-inline'`. React writes element `style` attributes for every
 *   field overlay, and CSP counts an attribute as inline style. The precise form would be
 *   `style-src-attr`, which is not yet universal; the exposure is styling only, and no
 *   third-party origin is admitted either way.
 *
 * `script-src 'self'` with no `'unsafe-inline'` and no `'unsafe-eval'`, `object-src 'none'`,
 * `base-uri 'none'`, `frame-ancestors 'none'`, and `form-action 'self'` — the last of which
 * means a `?return=` destination can never become a form target even if the allowlist were
 * misconfigured.
 *
 * ## The application's global CSP
 *
 * `AppServiceProvider` pushes Spatie's `AddCspHeaders` onto the global stack, and its
 * configured policy names `static.cloudflareinsights.com`. That origin must not appear on a
 * signing page. It does not: `AddCspHeaders` returns early when a response already carries a
 * `Content-Security-Policy`, which route middleware — running inside it — has by then set.
 * `SigningSecurityHeadersTest` asserts the resulting header, so the two cannot drift.
 *
 * The one case where no policy is emitted is a hot Vite dev server, which serves modules from
 * its own port; the same exemption Spatie's middleware makes, for the same reason, and it
 * cannot apply outside local development because `Vite::isRunningHot()` is false without a
 * hot file on disk.
 */
class SigningSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()',
        );

        if (! $this->viteIsHot()) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    /** The exact policy string, exposed so a test can assert it rather than re-derive it. */
    public function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]);
    }

    /**
     * True only in local development with `vite dev` running.
     *
     * Wrapped because `Vite::isRunningHot()` touches the filesystem, and a signing response
     * should not fail to render because a hot file check threw.
     */
    private function viteIsHot(): bool
    {
        try {
            return Vite::isRunningHot();
        } catch (\Throwable) {
            return false;
        }
    }
}
