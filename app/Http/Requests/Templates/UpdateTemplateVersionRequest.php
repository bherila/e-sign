<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

/**
 * PATCH /workspaces/{workspace}/templates/{template}/versions/{version}.
 *
 * Drafts only. A published version is immutable, and the controller answers 409 with the
 * instruction to draft the next version instead — the model refuses the write as well, so
 * the guarantee does not depend on this route remembering it.
 *
 * `document_id` is deliberately absent. Re-pointing a version at a different document would
 * change what the template means while keeping its number, and architecture invariant 2
 * binds an acceptance to a specific review revision. A different document is a different
 * version.
 */
class UpdateTemplateVersionRequest extends TemplateVersionContentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'field_schema' => ['sometimes', 'array'],
            ...$this->contentRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return $this->contentMessages();
    }
}
