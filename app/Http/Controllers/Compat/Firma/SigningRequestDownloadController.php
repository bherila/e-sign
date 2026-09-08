<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestDownloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compat\Firma\FirmaRequest;
use App\Http\Requests\Compat\Firma\StreamSigningRequestDownloadRequest;
use App\Http\Resources\Compat\Firma\SigningRequestDownloadResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two halves of `/download`: the JSON body, and the bytes it points at.
 *
 * They are separate routes because the profile's contract is separate. `describe()` needs an
 * API key and a resource scope like every other read; `stream()` carries no key at all and is
 * authorized by the signature on the URL it was reached through
 * (App\Domain\Integration\Firma\DownloadUrlIssuer explains why that trade is made, and how
 * narrow the resulting capability is).
 *
 * The bytes are streamed through this application on every driver, and no storage URL is ever
 * pre-signed (`docs/BLOB_STORAGE.md` rule 1). What is streamed is exactly what was retained:
 * the sealed executed PDF once the agreement is finished, and otherwise the reviewed revision
 * every party was shown, never re-rendered either way (AGENTS.md, "Retain originals
 * byte-for-byte").
 */
class SigningRequestDownloadController extends Controller
{
    public function __construct(private readonly SigningRequestDownloads $downloads) {}

    /** `GET /signing-requests/{id}/download` — JSON, with a short-lived link. */
    public function show(FirmaRequest $request): JsonResponse
    {
        return response()->json(
            (new SigningRequestDownloadResource($request->signingRequest(), $this->downloads))->resolve($request),
        );
    }

    /** `GET /downloads/{token}` — the bytes, behind a signed, expiring URL. */
    public function stream(StreamSigningRequestDownloadRequest $request): StreamedResponse
    {
        ['envelope' => $envelope, 'kind' => $kind] = $request->target();

        return $this->downloads->stream($envelope, $kind);
    }
}
