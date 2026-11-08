<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use App\Domain\Integration\Firma\DownloadUrlIssuer;

/**
 * `GET /signing-requests/{id}/documents/{document_id}/download` (issue #163).
 *
 * `type` is upstream's: `original` (the default), the document as reviewed and retained; or
 * `document`, the document sealed on its own once the agreement is complete.
 */
class ShowSigningRequestDocumentDownloadRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:'.implode(',', DownloadUrlIssuer::MEMBER_TYPES)],
        ];
    }

    public function type(): string
    {
        return (string) ($this->validated('type') ?? 'original');
    }

    public function documentId(): string
    {
        return (string) $this->route('document_id');
    }
}
