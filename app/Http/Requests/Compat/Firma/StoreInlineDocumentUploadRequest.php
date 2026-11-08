<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/**
 * `POST /documents/inline` — **upload a small document**, in one call (issue #182).
 *
 * Not an upstream route: this facade's addition, for a PDF up to 4.5 MB that a caller would
 * rather send as base64 in the JSON body than in two steps. It answers the same `document_id`
 * as `POST /documents` does once its bytes have arrived.
 */
class StoreInlineDocumentUploadRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file_name' => ['required', 'string', 'max:255', 'regex:/^[^\p{Cc}]+$/uD'],
            'document' => ['required', 'string'],
        ];
    }

    public function fileName(): string
    {
        return basename(str_replace('\\', '/', (string) $this->validated('file_name')));
    }

    public function document(): string
    {
        return (string) $this->validated('document');
    }
}
