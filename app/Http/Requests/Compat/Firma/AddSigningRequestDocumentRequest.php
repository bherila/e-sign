<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/** `POST /signing-requests/{id}/documents` (issue #182). */
class AddSigningRequestDocumentRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['required', 'string', 'max:191'],
            'position' => ['nullable', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:200', 'regex:/^[^\p{Cc}]*$/uD'],
            'original_filename' => ['nullable', 'string', 'max:255', 'regex:/^[^\p{Cc}]*$/uD'],
        ];
    }

    public function uploadId(): string
    {
        return (string) $this->validated('document_id');
    }

    public function position(): ?int
    {
        $position = $this->validated('position');

        return $position === null ? null : (int) $position;
    }

    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    public function originalFilename(): ?string
    {
        $name = $this->validated('original_filename');

        return is_string($name) && trim($name) !== '' ? basename(str_replace('\\', '/', trim($name))) : null;
    }
}
