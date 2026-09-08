<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\OpenApiDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * `GET /api/v1/openapi.json` — the API's own description, unauthenticated.
 *
 * Unauthenticated because a client generator needs the document *before* anyone has issued
 * a credential, and because it describes only the shape of the API: it names no workspace,
 * no envelope, and no secret. The same bytes are committed at
 * `resources/api/openapi-v1.json`, so nothing here is disclosed that a reader of this
 * repository does not already have.
 *
 * The file is sent verbatim rather than decoded and re-encoded, so the document a tool reads
 * over HTTP is byte-identical to the one in the repository.
 */
class OpenApiController extends Controller
{
    public function __invoke(): Response
    {
        return response(OpenApiDocument::json(), 200, [
            'Content-Type' => 'application/json',
            // A published contract is safe to cache, and clients fetch it on every codegen
            // run. Public rather than private: there is nothing caller-specific in it.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
