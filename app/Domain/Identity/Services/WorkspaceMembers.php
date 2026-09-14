<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceInvitation;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a workspace role is granted, changed or taken away after bootstrap (issue #110).
 *
 * The members page calls this, and so will the delegated-access adapter the identity provider
 * drives (#111). Two code paths that grant authority would be two sets of rules to keep equal,
 * and the provider would become the only way to administer a self-hosted installation whose
 * provider can be unreachable. So every rule lives here:
 *
 * - **Who may manage.** An owner or administrator of *this* workspace
 *   ({@see WorkspacePermission::ManageMembers}), checked here as well as at the HTTP boundary.
 * - **Ownership stays with owners.** Only an owner grants `owner`, and only an owner changes or
 *   removes an owner's membership.
 * - **A workspace always has an owner.** The last one cannot be demoted or removed.
 * - **Authority is decided under the same locks as the change.** Every change locks the
 *   workspace's owner rows, then the actor's and the target's membership rows together in id
 *   order, and only then reads the actor's role. An actor demoted by a concurrent request cannot
 *   finish a change on the authority they just lost, and two changes cannot each see another
 *   owner and leave none. One lock order everywhere is what keeps that from deadlocking: an
 *   invitation row, when one is involved, always comes before any membership row.
 * - **Removing access removes only access.** A membership row is deleted and nothing else;
 *   the schema's RESTRICT foreign keys keep every envelope, artifact and audit event.
 * - **Every change is audited**, once, and a change that changes nothing writes nothing.
 *
 * Identity never comes from an email address. A person joins by redeeming an invitation while
 * signed in, as whoever they are.
 */
final readonly class WorkspaceMembers
{
    /** How long an invitation link works. */
    public const INVITATION_LIFETIME_DAYS = 7;

    public function __construct(private AuditRecorder $audit) {}

    /**
     * The workspace's members, most senior role first, then by name.
     *
     * @return list<WorkspaceMembership>
     */
    public function members(Workspace $workspace): array
    {
        /** @var Collection<int, WorkspaceMembership> $memberships */
        $memberships = WorkspaceMembership::query()
            ->with('user')
            ->where('workspace_id', $workspace->getKey())
            ->get();

        return $memberships
            ->sort(static fn (WorkspaceMembership $a, WorkspaceMembership $b): int => [$b->role->rank(), (string) $a->user?->name, $a->id]
                <=> [$a->role->rank(), (string) $b->user?->name, $b->id])
            ->values()
            ->all();
    }

    /**
     * Invitations that can still be used, soonest to expire first.
     *
     * @return list<WorkspaceInvitation>
     */
    public function openInvitations(Workspace $workspace): array
    {
        return WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->getKey())
            ->open()
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Create an invitation, and the token for its link.
     *
     * The token is returned once and stored only as a digest, so the caller has to hand it over
     * now: nothing can read it back later.
     *
     * @return array{0: WorkspaceInvitation, 1: string} The invitation and its one-time token.
     *
     * @throws MembershipChangeRefused
     */
    public function invite(Workspace $workspace, User $actor, WorkspaceRole $role): array
    {
        $token = bin2hex(random_bytes(32));

        $invitation = DB::transaction(function () use ($workspace, $actor, $role, $token): WorkspaceInvitation {
            $this->lockedOwners($workspace);
            [$actorRole] = $this->lockedActorAndTarget($workspace, $actor);

            $this->assertManages($actorRole);

            if ($role === WorkspaceRole::Owner) {
                $this->assertOwner($actorRole);
            }

            $invitation = WorkspaceInvitation::create([
                'workspace_id' => $workspace->getKey(),
                'role' => $role,
                'token_sha256' => self::digest($token),
                'created_by' => $actor->getKey(),
                'expires_at' => CarbonImmutable::now()->addDays(self::INVITATION_LIFETIME_DAYS),
            ]);

            $this->audit->record(AuditActor::user($actor), 'identity.member_invited', $workspace, [
                'invitation_public_id' => $invitation->public_id,
                'role' => $role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]);

            return $invitation;
        });

        return [$invitation, $token];
    }

    /**
     * @throws MembershipChangeRefused
     */
    public function revokeInvitation(Workspace $workspace, User $actor, WorkspaceInvitation $invitation): void
    {
        DB::transaction(function () use ($workspace, $actor, $invitation): void {
            // The invitation row first, as redeem() takes it. Revoking and accepting one link at the
            // same moment then wait on that one row, instead of each holding what the other needs
            // (the owner rows here, the invitation there) and deadlocking into a 500.
            $locked = WorkspaceInvitation::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->first();

            $this->lockedOwners($workspace);
            [$actorRole] = $this->lockedActorAndTarget($workspace, $actor);

            $this->assertManages($actorRole);

            // Already accepted, revoked or expired: there is nothing left to take back, and
            // saying so twice would add an audit event that records nothing happening.
            if (! $locked instanceof WorkspaceInvitation || ! $locked->isOpen()) {
                throw MembershipChangeRefused::invitationUnavailable();
            }

            $locked->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $actor->getKey()])->save();

            $this->audit->record(AuditActor::user($actor), 'identity.member_invitation_revoked', $workspace, [
                'invitation_public_id' => $locked->public_id,
                'role' => $locked->role->value,
            ]);
        });
    }

    /**
     * The open invitation a token names, or null. Reads only: showing the invitation page must
     * never consume or alter anything.
     */
    public function openInvitationFor(string $token): ?WorkspaceInvitation
    {
        if (! self::isWellFormedToken($token)) {
            return null;
        }

        $invitation = WorkspaceInvitation::query()
            ->with('workspace')
            ->where('token_sha256', self::digest($token))
            ->first();

        return $invitation instanceof WorkspaceInvitation && $invitation->isOpen() ? $invitation : null;
    }

    /**
     * Join the invitation's workspace, in its role, as the signed-in person.
     *
     * @throws MembershipChangeRefused
     */
    public function redeem(string $token, User $user): WorkspaceMembership
    {
        if (! self::isWellFormedToken($token)) {
            throw MembershipChangeRefused::invitationUnavailable();
        }

        return DB::transaction(function () use ($token, $user): WorkspaceMembership {
            $invitation = WorkspaceInvitation::query()
                ->where('token_sha256', self::digest($token))
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof WorkspaceInvitation || ! $invitation->isOpen()) {
                throw MembershipChangeRefused::invitationUnavailable();
            }

            // A workspace deleted since the link was made is not somewhere anybody can join.
            if (! Workspace::query()->whereKey($invitation->workspace_id)->exists()) {
                throw MembershipChangeRefused::invitationUnavailable();
            }

            $existing = WorkspaceMembership::query()
                ->where('workspace_id', $invitation->workspace_id)
                ->where('user_id', $user->getKey())
                ->exists();

            // Left open. A member who follows a link meant for somebody else must not use it up,
            // and must not quietly change their own role through it either.
            if ($existing) {
                throw MembershipChangeRefused::alreadyMember();
            }

            try {
                $membership = WorkspaceMembership::create([
                    'workspace_id' => $invitation->workspace_id,
                    'user_id' => $user->getKey(),
                    'role' => $invitation->role,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two different invitations to one workspace, redeemed by the same person at the
                // same moment: each saw no membership. The loser is the documented refusal, and
                // rolling this transaction back leaves its invitation open.
                throw MembershipChangeRefused::alreadyMember();
            }

            $invitation->forceFill(['redeemed_at' => CarbonImmutable::now(), 'redeemed_by' => $user->getKey()])->save();

            $this->audit->record(AuditActor::user($user), 'identity.member_joined', $invitation->workspace, [
                'invitation_public_id' => $invitation->public_id,
                'user_id' => $user->getKey(),
                'role' => $invitation->role->value,
            ]);

            return $membership;
        });
    }

    /**
     * @throws MembershipChangeRefused
     */
    public function changeRole(Workspace $workspace, User $actor, WorkspaceMembership $membership, WorkspaceRole $role): WorkspaceMembership
    {
        return DB::transaction(function () use ($workspace, $actor, $membership, $role): WorkspaceMembership {
            $owners = $this->lockedOwners($workspace);
            [$actorRole, $locked] = $this->lockedActorAndTarget($workspace, $actor, $membership);

            $this->assertManages($actorRole);

            // Gone since the page was read, or never in this workspace: either way there is
            // nothing here this actor may change.
            if (! $locked instanceof WorkspaceMembership) {
                throw MembershipChangeRefused::notPermitted();
            }

            if ($locked->role === $role) {
                return $locked;
            }

            if ($locked->role === WorkspaceRole::Owner || $role === WorkspaceRole::Owner) {
                $this->assertOwner($actorRole);
            }

            if ($locked->role === WorkspaceRole::Owner && count($owners) <= 1) {
                throw MembershipChangeRefused::lastOwner();
            }

            $from = $locked->role;
            $locked->forceFill(['role' => $role])->save();

            $this->audit->record(AuditActor::user($actor), 'identity.member_role_changed', $workspace, [
                'user_id' => $locked->user_id,
                'from' => $from->value,
                'to' => $role->value,
            ]);

            return $locked;
        });
    }

    /**
     * @throws MembershipChangeRefused
     */
    public function remove(Workspace $workspace, User $actor, WorkspaceMembership $membership): void
    {
        DB::transaction(function () use ($workspace, $actor, $membership): void {
            $owners = $this->lockedOwners($workspace);
            [$actorRole, $locked] = $this->lockedActorAndTarget($workspace, $actor, $membership);

            $this->assertManages($actorRole);

            if (! $locked instanceof WorkspaceMembership) {
                throw MembershipChangeRefused::notPermitted();
            }

            if ($locked->role === WorkspaceRole::Owner) {
                $this->assertOwner($actorRole);

                if (count($owners) <= 1) {
                    throw MembershipChangeRefused::lastOwner();
                }
            }

            $locked->delete();

            $this->audit->record(AuditActor::user($actor), 'identity.member_removed', $workspace, [
                'user_id' => $locked->user_id,
                'role' => $locked->role->value,
            ]);
        });
    }

    public static function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 64 lowercase hex characters: the only shape `invite()` ever issues. */
    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }

    /**
     * The workspace's owner rows, locked, in id order. Always the first lock a change takes.
     *
     * @return list<WorkspaceMembership>
     */
    private function lockedOwners(Workspace $workspace): array
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('role', WorkspaceRole::Owner->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();
    }

    /**
     * The actor's current role and the target membership, from one locking read in id order.
     *
     * Read fresh, never from `Workspace::membershipFor()`'s per-instance cache, and under the lock,
     * so the role that authorises a change is the role the actor holds when the change commits.
     *
     * @return array{0: WorkspaceRole|null, 1: WorkspaceMembership|null}
     */
    private function lockedActorAndTarget(Workspace $workspace, User $actor, ?WorkspaceMembership $target = null): array
    {
        /** @var Collection<int, WorkspaceMembership> $rows */
        $rows = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(static function ($query) use ($actor, $target): void {
                $query->where('user_id', $actor->getKey());

                if ($target instanceof WorkspaceMembership) {
                    $query->orWhere('id', $target->getKey());
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return [
            $rows->firstWhere('user_id', $actor->getKey())?->role,
            $target instanceof WorkspaceMembership ? $rows->firstWhere('id', $target->getKey()) : null,
        ];
    }

    /**
     * @throws MembershipChangeRefused
     */
    private function assertManages(?WorkspaceRole $actorRole): void
    {
        if (! $actorRole?->can(WorkspacePermission::ManageMembers)) {
            throw MembershipChangeRefused::notPermitted();
        }
    }

    /**
     * @throws MembershipChangeRefused
     */
    private function assertOwner(?WorkspaceRole $actorRole): void
    {
        if ($actorRole !== WorkspaceRole::Owner) {
            throw MembershipChangeRefused::ownerOnly();
        }
    }
}
