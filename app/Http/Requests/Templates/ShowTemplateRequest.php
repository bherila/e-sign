<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * GET /workspaces/{workspace}/templates/{template}.
 *
 * There is nothing to validate: the request carries only route parameters, which the base
 * class resolves and scopes to the caller's workspace.
 */
class ShowTemplateRequest extends WorkspaceTemplateRequest
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
        return [];
    }
}
