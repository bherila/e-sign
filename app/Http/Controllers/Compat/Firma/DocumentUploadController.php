<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Integration\Firma\Uploads\FirmaDocumentUpload;
use App\Domain\Integration\Firma\Uploads\FirmaDocumentUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compat\Firma\PutDocumentUploadContentRequest;
use App\Http\Requests\Compat\Firma\StoreDocumentUploadRequest;
use App\Http\Requests\Compat\Firma\StoreInlineDocumentUploadRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Uploading a document ahead of using it (issue #182). Two ways, named for what they are for:
 *
 * - **Upload a small document** — `POST /documents/inline`: base64 in the JSON body, up to 4.5 MB.
 * - **Upload a large document** — `POST /documents`, then `PUT` the raw bytes to its `upload_url`;
 *   up to 50 MB.
 *
 * See App\Domain\Integration\Firma\Uploads\FirmaDocumentUploads.
 */
class DocumentUploadController extends Controller
{
    public function __construct(private readonly FirmaDocumentUploads $uploads) {}

    /** **Upload a large document**, step one: `POST /documents`. */
    public function storeLarge(StoreDocumentUploadRequest $request): JsonResponse
    {
        $issued = $this->uploads->issue(
            $request->workspace(),
            $request->credential(),
            $request->fileName(),
            $request->contentType(),
            $request->fileSize(),
        );

        return response()->json([
            'document_id' => $issued['upload']->public_id,
            'upload_url' => $issued['upload_url'],
            'upload_token' => $issued['upload_token'],
            'expires_in' => $issued['expires_in'],
        ], 201);
    }

    /** **Upload a large document**, step two: `PUT {upload_url}` with the raw bytes. */
    public function putContent(PutDocumentUploadContentRequest $request): JsonResponse
    {
        $upload = $this->uploads->receive($request->uploadId(), $request->body(), $request->header('Content-Type'));

        return response()->json(self::uploaded($upload));
    }

    /** **Upload a small document**, in one call: `POST /documents/inline`. */
    public function storeSmall(StoreInlineDocumentUploadRequest $request): JsonResponse
    {
        $upload = $this->uploads->inline($request->workspace(), $request->credential(), $request->fileName(), $request->document());

        return response()->json(self::uploaded($upload), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private static function uploaded(FirmaDocumentUpload $upload): array
    {
        return [
            'document_id' => $upload->public_id,
            'file_name' => $upload->file_name,
            'page_count' => $upload->document?->page_count,
            'expires_in' => max(0, (int) CarbonImmutable::now()->diffInSeconds($upload->expires_at, false)),
            'max_bytes' => FirmaProfile::MAX_UPLOAD_BYTES,
        ];
    }
}
