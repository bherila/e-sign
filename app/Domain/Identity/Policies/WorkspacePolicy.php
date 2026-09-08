<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Models\Workspace;
use App\Models\User;

/**
 * Authorization for everything scoped to a workspace.
 *
 * Two rules hold for every ability:
 *
 *  1. Authority comes from a membership row and the WorkspaceRole permission map. There is
 *     no super-user branch, no `is_admin` short circuit, and no "first user" exception. A
 *     directory grant at the identity provider admits someone to the application; it does
 *     not give them a workspace.
 *  2. A non-member is denied on every ability, including `view`. That is what makes
 *     cross-workspace probing by id, public id, or slug useless: belonging to workspace A
 *     tells the policy nothing about workspace B.
 *
 * Denials return false rather than throwing so the caller decides between 403 and 404;
 * lookups should go through Workspace::whereMemberOf() and 404 instead.
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::View);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::Update);
    }

    /**
     * Owner only. Deleting a workspace is a soft delete and still leaves every executed
     * instrument in place; the RESTRICT foreign keys make a hard delete impossible while
     * anything references it.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::Delete);
    }

    /**
     * Owner and admin. Removing a membership removes access only — never an envelope,
     * an artifact, or an audit event.
     */
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::ManageMembers);
    }

    public function createTemplates(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::CreateTemplates);
    }

    public function createEnvelopes(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::CreateEnvelopes);
    }

    public function readAudit(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::ReadAudit);
    }

    /**
     * Owner only. Service credentials are separate principals and rotating one can cut off
     * an integration, so it stays with the role that answers for the workspace.
     */
    public function rotateCredentials(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, WorkspacePermission::RotateCredentials);
    }

    private function allows(User $user, Workspace $workspace, WorkspacePermission $permission): bool
    {
        return $workspace->roleFor($user)?->can($permission) ?? false;
    }
}
