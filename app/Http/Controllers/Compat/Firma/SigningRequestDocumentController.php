<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat\Firma;

use App\Domain\Integration\Firma\SigningRequestDocumentSets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compat\Firma\AddSigningRequestDocumentRequest;
use App\Http\Requests\Compat\Firma\FirmaRequest;
use App\Http\Requests\Compat\Firma\ReorderSigningRequestDocumentsRequest;
use App\Http\Requests\Compat\Firma\UpdateSigningRequestDocumentRequest;
use Illuminate\Http\JsonResponse;

/**
 * A draft's `/documents` routes (issue #182). Each answers the whole set after the change.
 * See App\Domain\Integration\Firma\SigningRequestDocumentSets.
 */
class SigningRequestDocumentController extends Controller
{
    public function __construct(private readonly SigningRequestDocumentSets $sets) {}

    /** `POST /signing-requests/{id}/documents` — add a document. */
    public function store(AddSigningRequestDocumentRequest $request): JsonResponse
    {
        return response()->json($this->sets->add(
            $request->workspace(),
            $request->signingRequest(),
            $request->credential(),
            $request->uploadId(),
            $request->position(),
            $request->label(),
            $request->originalFilename(),
        ));
    }

    /** `PUT /signing-requests/{id}/documents/order` — reorder the documents. */
    public function reorder(ReorderSigningRequestDocumentsRequest $request): JsonResponse
    {
        return response()->json($this->sets->reorder($request->signingRequest(), $request->credential(), $request->documentIds()));
    }

    /** `PATCH /signing-requests/{id}/documents/{document_id}` — replace or rename a document. */
    public function update(UpdateSigningRequestDocumentRequest $request): JsonResponse
    {
        [$kind, $value] = $request->change();

        return response()->json($kind === 'replace'
            ? $this->sets->replace($request->workspace(), $request->signingRequest(), $request->credential(), $request->documentId(), (string) $value)
            : $this->sets->rename($request->signingRequest(), $request->credential(), $request->documentId(), $value));
    }

    /** `DELETE /signing-requests/{id}/documents/{document_id}` — remove a document. */
    public function destroy(FirmaRequest $request): JsonResponse
    {
        $documentId = $request->route('document_id');

        return response()->json($this->sets->remove(
            $request->signingRequest(),
            $request->credential(),
            is_string($documentId) ? $documentId : '',
        ));
    }
}
