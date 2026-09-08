<?php

declare(strict_types=1);

namespace App\Http\Requests\Editor;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Http\Requests\Templates\WorkspaceTemplateRequest;

/**
 * GET /workspaces/{workspace}/templates/{template}/versions/{version}/editor.
 *
 * `view`, the same floor the version endpoint takes, so an auditor can open the editor and
 * read a version's field placement. Whether the page can *write* is a second question,
 * answered by `canEdit()` below and rendered as the editor's `readOnly` flag: an auditor is
 * shown the document and the fields and is given no Save button, which is most of what
 * auditing a field set means.
 *
 * Gating the page itself on `createTemplates` would have been the smaller rule and the wrong
 * one — it would make the only visual view of a field set unavailable to the role whose whole
 * job is looking at things without changing them.
 */
class ShowFieldEditorRequest extends WorkspaceTemplateRequest
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

    /** Whether this caller's workspace role carries the permission the PATCH endpoint takes. */
    public function canEdit(): bool
    {
        return $this->currentUser()->can(
            WorkspacePermission::CreateTemplates->value,
            $this->workspace(),
        );
    }
}
