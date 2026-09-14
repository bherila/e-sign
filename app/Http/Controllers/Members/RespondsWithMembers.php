<?php

declare(strict_types=1);

namespace App\Http\Controllers\Members;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceInvitation;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The members page's one data shape, and its one refusal shape.
 *
 * Every action answers with the whole, current state of the page rather than a diff, so the page
 * never shows a role that is not the one stored. A refusal is the service's own message and code.
 */
trait RespondsWithMembers
{
    /**
     * @return array<string, mixed>
     */
    protected function membersPayload(WorkspaceMembers $members, Workspace $workspace, User $viewer): array
    {
        $viewerRole = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $viewer->getKey())
            ->first()
            ?->role;

        $isOwner = $viewerRole === WorkspaceRole::Owner;

        return [
            'workspace' => [
                'publicId' => $workspace->public_id,
                'name' => $workspace->name,
            ],
            'viewer' => [
                'role' => $viewerRole?->value,
                'isOwner' => $isOwner,
            ],
            'roles' => array_map(static fn (WorkspaceRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                // Presentation only. The service refuses an administrator who names `owner`
                // whatever the page offers.
                'grantable' => $isOwner || $role !== WorkspaceRole::Owner,
            ], WorkspaceRole::cases()),
            'members' => array_map(static fn (WorkspaceMembership $membership): array => [
                'publicId' => $membership->public_id,
                'name' => (string) $membership->user?->name,
                'email' => self::contactAddress($membership->user),
                'role' => $membership->role->value,
                'roleLabel' => $membership->role->label(),
                'isYou' => $membership->user_id === $viewer->getKey(),
            ], $members->members($workspace)),
            'invitations' => array_map(static fn (WorkspaceInvitation $invitation): array => [
                'publicId' => $invitation->public_id,
                'role' => $invitation->role->value,
                'roleLabel' => $invitation->role->label(),
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ], $members->openInvitations($workspace)),
            'urls' => [
                'self' => route('members.index', ['workspace' => $workspace->public_id]),
                'invitations' => route('members.invitations.store', ['workspace' => $workspace->public_id]),
                // Templates: the page puts a member's or an invitation's public id where
                // `placeholder` stands. The placeholder is a valid ULID so the route can build them.
                'member' => route('members.update', ['workspace' => $workspace->public_id, 'member' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']),
                'invitation' => route('members.invitations.destroy', ['workspace' => $workspace->public_id, 'invitation' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']),
                'placeholder' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ],
        ];
    }

    protected function refused(MembershipChangeRefused $refusal): JsonResponse
    {
        $status = match ($refusal->reason) {
            MembershipChangeRefused::NOT_PERMITTED, MembershipChangeRefused::OWNER_ONLY => 403,
            default => 422,
        };

        return response()->json(['message' => $refusal->getMessage(), 'code' => $refusal->reason], $status);
    }

    /**
     * An address fit to show, or null.
     *
     * An account provisioned ahead of its first sign-in carries a reserved `.invalid` placeholder
     * that means "not known yet"; showing it would read as a real, wrong address.
     */
    private static function contactAddress(?User $user): ?string
    {
        $email = (string) $user?->email;

        return $email === '' || str_ends_with(strtolower($email), '.invalid') ? null : $email;
    }
}
