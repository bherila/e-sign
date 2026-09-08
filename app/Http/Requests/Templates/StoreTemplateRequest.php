<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * POST /workspaces/{workspace}/templates.
 *
 * Sender and above, through `createTemplates`. An auditor may read every template in the
 * workspace and may create none.
 *
 * Only a name and a description: a template has no content of its own, so there is nothing
 * else to accept here. Content arrives as a version, which needs a document that has already
 * passed preflight.
 */
class StoreTemplateRequest extends WorkspaceTemplateRequest
{
    protected function permission(): WorkspacePermission
    {
        return WorkspacePermission::CreateTemplates;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the template a name senders will recognise.',
        ];
    }

    public function templateName(): string
    {
        return trim((string) $this->input('name'));
    }

    public function description(): ?string
    {
        $description = $this->input('description');

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }
}
