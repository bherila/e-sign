<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/** `PUT /signing-requests/{id}/documents/order` (issue #182). */
class ReorderSigningRequestDocumentsRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['required', 'string', 'max:191'],
        ];
    }

    /**
     * @return list<string>
     */
    public function documentIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('document_ids')));
    }
}
