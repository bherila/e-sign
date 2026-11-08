<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

use App\Domain\Integration\Firma\FirmaErrorCode;
use App\Domain\Integration\Firma\FirmaException;

/**
 * `PATCH /signing-requests/{id}/documents/{document_id}` (issue #182): exactly one of
 * `document_id` (replace the document with an upload) or `label` (rename; `null` clears it).
 */
class UpdateSigningRequestDocumentRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['nullable', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:200', 'regex:/^[^\p{Cc}]*$/uD'],
        ];
    }

    /**
     * @return array{0: 'replace', 1: string}|array{0: 'rename', 1: string|null}
     *
     * @throws FirmaException
     */
    public function change(): array
    {
        $replace = $this->has('document_id');
        $rename = $this->has('label') || array_key_exists('label', $this->all());

        if ($replace === $rename) {
            throw FirmaException::of(
                FirmaErrorCode::InvalidRequest,
                'Send exactly one of `document_id` (replace the document with an upload) or `label` (rename it).',
            );
        }

        if ($replace) {
            return ['replace', (string) $this->validated('document_id')];
        }

        $label = $this->validated('label');

        return ['rename', is_string($label) && trim($label) !== '' ? trim($label) : null];
    }

    public function documentId(): string
    {
        return $this->routeValue('document_id');
    }
}
