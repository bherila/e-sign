<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * GET /workspaces/{workspace}/templates.
 *
 * `view` is the floor for every workspace role, so an auditor can read the template list
 * without being able to change anything on it.
 *
 * `retired` is a filter, not a flag on the response: absent lists everything, `false` lists
 * what a sender may still pick, `true` lists what has been withdrawn. Every row carries its
 * own `retired_at` either way, so a client never has to infer the filter from the contents.
 */
class IndexTemplatesRequest extends WorkspaceTemplateRequest
{
    protected function permission(): WorkspacePermission
    {
        return WorkspacePermission::View;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'retired' => ['sometimes', 'boolean'],
        ];
    }

    /** Null means "no filter", which is not the same as `false`. */
    public function retiredFilter(): ?bool
    {
        return $this->has('retired') ? $this->boolean('retired') : null;
    }
}
