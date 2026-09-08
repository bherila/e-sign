<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\ShowTemplateVersionRequest;
use Illuminate\Http\Response;

/**
 * GET /workspaces/{workspace}/templates/{template}/versions/{version}/schema.json.
 *
 * The canonical export: the exact bytes
 * App\Domain\Preparation\Schema\FieldSchemaDocument::canonicalJson() produces for this
 * version's field set, with its digest in a header. Fixed key order, fixed numeric spelling,
 * no insignificant whitespace — so two exports of the same version are byte-identical, and
 * so a client can diff two versions or verify the digest without re-canonicalising anything
 * (docs/preparation/field-schema.md, "Canonical form").
 *
 * The bytes are regenerated from the stored value rather than echoed out of the column,
 * because a MySQL `JSON` column does not preserve object key order; re-importing is
 * order-insensitive and reproduces the same bytes on every supported engine. `X-Field-Schema-Sha256`
 * is the digest recorded when the version was written, so a mismatch between it and the body
 * is detectable by the caller rather than only by us.
 *
 * A separate route from the version payload because this one has to be *the bytes*: the
 * version response is a representation, and a representation is allowed to grow a field.
 *
 * It is a GET and it is harmless: it reads one row, publishes nothing, and consumes nothing
 * (AGENTS.md, "GET is harmless").
 */
class TemplateVersionSchemaController extends Controller
{
    public function show(ShowTemplateVersionRequest $request): Response
    {
        $version = $request->templateVersion();

        return response(
            $version->canonicalFieldSchemaJson(),
            200,
            [
                'Content-Type' => 'application/json',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
                'X-Field-Schema-Sha256' => $version->field_schema_sha256,
                'Content-Disposition' => sprintf(
                    'attachment; filename="template-%s-v%d-schema.json"',
                    $version->template?->public_id ?? 'unknown',
                    $version->version,
                ),
            ],
        );
    }
}
