<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * PATCH /workspaces/{workspace}/templates/{template}.
 *
 * Renames, re-describes, and retires or restores. None of it reaches a version: a rename
 * cannot change what an envelope already out for signature says, because the envelope holds
 * a snapshot rather than a reference (docs/HANDOFF.md section 6).
 *
 * `changes()` returns only the keys the caller actually sent, so PATCH means PATCH: sending
 * `{"description": null}` clears the description, and omitting it leaves it alone. `sometimes`
 * on every rule is what makes that distinction survive validation.
 */
class UpdateTemplateRequest extends WorkspaceTemplateRequest
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
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'retired' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name?: string, description?: string|null}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('description')) {
            $description = $this->input('description');
            $changes['description'] = is_string($description) && trim($description) !== ''
                ? trim($description)
                : null;
        }

        return $changes;
    }

    /** Null means the caller said nothing about retirement. */
    public function retired(): ?bool
    {
        return $this->has('retired') ? $this->boolean('retired') : null;
    }
}
