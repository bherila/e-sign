<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/**
 * `POST /documents` — **upload a large document**, step one (issue #182).
 *
 * Upstream's request: the file's name, size and type. The answer is a URL to PUT the raw bytes
 * to, and the `document_id` to use once they have arrived. For a PDF small enough for a JSON body
 * (up to 4.5 MB), `POST /documents/inline` does it in one call.
 */
class StoreDocumentUploadRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file_name' => ['required', 'string', 'max:255', 'regex:/^[^\p{Cc}]+$/uD'],
            'file_size' => ['required', 'integer', 'min:1'],
            'content_type' => ['required', 'string', 'max:127'],
        ];
    }

    public function fileName(): string
    {
        return basename(str_replace('\\', '/', (string) $this->validated('file_name')));
    }

    public function fileSize(): int
    {
        return (int) $this->validated('file_size');
    }

    public function contentType(): string
    {
        return strtolower(trim((string) $this->validated('content_type')));
    }
}
