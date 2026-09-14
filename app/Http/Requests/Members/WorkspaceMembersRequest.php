<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceInvitation;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Shared resolution and authorization for the members page and its actions (issue #110).
 *
 * The same shape as the document routes: the workspace is looked up through
 * `Workspace::whereMemberOf()`, so a workspace the caller does not belong to is a 404 rather
 * than a 403 that would confirm it exists. A membership or an invitation is looked up inside
 * that workspace by its public id, so an id from another workspace is a 404 too.
 *
 * A 403 is left for what it describes: a member whose role cannot manage members.
 * {@see WorkspaceMembers} checks the same rule again, and adds the
 * owner-only and last-owner rules that depend on the row being changed.
 */
abstract class WorkspaceMembersRequest extends FormRequest
{
    private ?Workspace $workspace = null;

    private ?WorkspaceMembership $membership = null;

    private ?WorkspaceInvitation $invitation = null;

    public function authorize(): bool
    {
        return Gate::forUser($this->currentUser())->allows(WorkspacePermission::ManageMembers->value, $this->workspace());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function workspace(): Workspace
    {
        return $this->workspace ??= Workspace::query()
            ->whereMemberOf($this->currentUser())
            ->where('public_id', $this->routeValue('workspace'))
            ->firstOrFail();
    }

    public function membership(): WorkspaceMembership
    {
        return $this->membership ??= WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace()->getKey())
            ->where('public_id', $this->routeValue('member'))
            ->firstOrFail();
    }

    public function invitation(): WorkspaceInvitation
    {
        return $this->invitation ??= WorkspaceInvitation::query()
            ->where('workspace_id', $this->workspace()->getKey())
            ->where('public_id', $this->routeValue('invitation'))
            ->firstOrFail();
    }

    public function currentUser(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            // The `auth` middleware runs first, so this is unreachable over HTTP; it exists so the
            // failure is loud rather than an authorization check against null.
            throw new RuntimeException('A members route ran without an authenticated user.');
        }

        return $user;
    }

    private function routeValue(string $name): string
    {
        $value = $this->route($name);

        return is_string($value) ? $value : '';
    }
}
