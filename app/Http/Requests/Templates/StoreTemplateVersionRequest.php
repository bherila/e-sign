<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Preparation\Documents\Models\Document;

/**
 * POST /workspaces/{workspace}/templates/{template}/versions.
 *
 * Drafts the next version from a document and a field schema document. This is also how a
 * template is edited after a publish: there is no route that changes a published version, so
 * "change the fields and send again" is another POST here, which produces version n+1 and
 * leaves version n exactly as anybody who already received it saw it.
 *
 * `document_id` is resolved inside the workspace this request already resolved inside the
 * caller's memberships, so a document belonging to another tenant is a **404** rather than a
 * 403 or a validation message — an outsider must not be able to confirm that a document
 * ULID exists. Whether the document is usable (it must be `ready`, and it must have a review
 * revision) is a domain decision and comes back as a 422.
 */
class StoreTemplateVersionRequest extends TemplateVersionContentRequest
{
    private ?Document $document = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['required', 'string', 'ulid'],
            'field_schema' => ['required', 'array'],
            ...$this->contentRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'document_id.required' => 'Name the document this template version snapshots.',
            'field_schema.required' => 'Send the native field schema document this version defines.',
            ...$this->contentMessages(),
        ];
    }

    /** Scoped to the resolved workspace: a foreign document id is a 404. */
    public function document(): Document
    {
        return $this->document ??= Document::query()
            ->inWorkspace($this->workspace())
            ->where('public_id', (string) $this->input('document_id'))
            ->firstOrFail();
    }
}
