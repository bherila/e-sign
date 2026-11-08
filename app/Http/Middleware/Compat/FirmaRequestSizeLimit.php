<?php

declare(strict_types=1);

namespace App\Http\Middleware\Compat;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The documented request-body ceiling for this surface: 6 MiB (6,291,456 bytes).
 *
 * Upstream refuses a larger body with a `413` and its `REQUEST_TOO_LARGE` code on the routes that accept a
 * document. The inline base64 document is the one body here that can grow without bound,
 * and a caller who learns about a ceiling from a gateway's `502` — what upstream answered
 * before it documented this — learns nothing it can act on. The refusal is raised here,
 * before the body is parsed, a Form Request runs, or anything is created: by the time a
 * controller could ask the question, the work the ceiling exists to avoid has happened.
 *
 * The size is the declared `Content-Length` when the client sent one — which is also the
 * only measurement left when the SAPI has already discarded an over-limit body — and the
 * received body measured directly when it did not, as with a chunked request.
 *
 * The refusal is a {@see FirmaException} so {@see FirmaErrorBoundary}, which wraps this
 * middleware on the surface's routes, renders it in the upstream envelope like every
 * other refusal here.
 */
class FirmaRequestSizeLimit
{
    /** Upstream's documented ceiling for a request body on this surface, in bytes. */
    public const MAX_BODY_BYTES = 6_291_456;

    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');
        $size = $declared !== null ? (int) $declared : strlen($request->getContent());

        if ($size > self::MAX_BODY_BYTES) {
            throw FirmaException::of(
                FirmaErrorCode::RequestTooLarge,
                'The request body is larger than the 6 MiB limit for this surface.',
            );
        }

        return $next($request);
    }
}
