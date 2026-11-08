<?php

declare(strict_types=1);

namespace App\Http\Middleware\Compat;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaException;
use App\Domain\Integration\Firma\FirmaProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The documented request-body ceiling for this surface: 6 MiB (6,291,456 bytes).
 *
 * Upstream refuses a larger body with a `413` and its `REQUEST_TOO_LARGE` code on the routes
 * that accept a document. The inline base64 document is the one body here that can grow
 * without bound, and a caller who learns about a ceiling from a gateway's `502` — what
 * upstream answered before it documented this — learns nothing it can act on.
 *
 * ## Global, and first
 *
 * The refusal has to land before anything decodes the body, and route middleware is too
 * late for that: Laravel's default global `TrimStrings` and `ConvertEmptyStringsToNull` read
 * `$request->json()` — decoding and walking the whole document — before any route
 * middleware runs. So this is registered in the *global* stack, ahead of the framework's
 * defaults (bootstrap/app.php), and scoped here to this surface's path. Every other route
 * passes straight through.
 *
 * Because it runs before {@see FirmaErrorBoundary} (a route middleware), it renders the
 * refusal in the surface's envelope itself rather than throwing for the boundary to catch.
 *
 * The size is the declared `Content-Length` when the client sent one — which is also the
 * only measurement left when the SAPI has already discarded an over-limit body — and the
 * received body measured directly when it did not, as with a chunked request.
 */
class FirmaRequestSizeLimit
{
    /** Upstream's documented ceiling for a request body on this surface, in bytes. */
    public const MAX_BODY_BYTES = 6_291_456;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(FirmaProfile::BASE_PATH, FirmaProfile::BASE_PATH.'/*')) {
            return $next($request);
        }

        $declared = $request->headers->get('Content-Length');
        $size = $declared !== null ? (int) $declared : strlen($request->getContent());

        if ($size > self::MAX_BODY_BYTES) {
            $refusal = FirmaException::of(
                FirmaErrorCode::RequestTooLarge,
                'The request body is larger than the 6 MiB limit for this surface.',
            );

            return response()->json($refusal->toArray(), $refusal->status());
        }

        return $next($request);
    }
}
