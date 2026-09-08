<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Domain\Identity\Enums\WorkspacePermission;

/**
 * GET /workspaces/{workspace}/documents/{document}.
 *
 * `view` is the floor for every workspace role, so an auditor can read metadata, digests,
 * and the preflight report without being able to upload. There is nothing to validate: the
 * request carries only route parameters, which the base class resolves and scopes.
 */
class ShowDocumentRequest extends WorkspaceDocumentRequest
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
