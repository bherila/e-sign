<?php

declare(strict_types=1);

namespace App\Domain\Identity\DelegatedAccess;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\PendingAccount;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Models\User;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Answers the identity provider's delegated access requests, contract version 2 (issue #111).
 *
 * The provider proves who is acting; this decides what they may see and change, with the same
 * rules the members page applies, because every change goes through {@see WorkspaceMembers}:
 *
 * - **Who may act.** The verified subject is resolved to a local account through its identity
 *   binding, exactly as sign-in resolves it. The account must be active and must own or administer
 *   at least one workspace. The provider's own administrators get nothing from that alone.
 * - **What is visible.** Only workspaces the actor owns or administers, and only the memberships
 *   people hold in those workspaces. Anything else a person belongs to is neither shown nor
 *   changeable from here.
 * - **What is editable.** Whatever `WorkspaceMembers` would let the actor change: an owner's
 *   membership is editable only by an owner, and the last owner is never removed.
 * - **Revisions.** A read returns a digest of exactly what it showed. An update must name it and
 *   is compared under the same locks the changes take, so a concurrent change is a conflict, never
 *   something quietly overwritten.
 * - **Provisioning.** An update with a null revision creates the account for a subject nobody has
 *   bound yet, bound to this provider's issuer and that exact subject, and grants the memberships
 *   asked for. It never adopts an existing row found by address.
 */
final readonly class ApplicationAccessAdapter
{
    public const PAGE_SIZE = 50;

    /** Recorded on every audit event this surface causes. */
    private const AUDIT_CONTEXT = ['via' => 'delegated_access'];

    public function __construct(
        private WorkspaceMembers $members,
        private AuditRecorder $audit,
        private Encrypter $encrypter,
        private DelegatedAccessSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  A request already validated by {@see DelegatedContract::request()}.
     * @return array<string, mixed> The response envelope.
     *
     * @throws DelegatedAccessException
     */
    public function handle(string $actorSubject, array $payload): array
    {
        $actor = $this->boundUser($actorSubject);

        if (! $actor instanceof User || $actor->isDisabled()) {
            throw new DelegatedAccessException('not_authorized', 403);
        }

        $managed = $this->managedWorkspaces($actor);

        if ($managed === []) {
            throw new DelegatedAccessException('not_authorized', 403);
        }

        $operation = (string) $payload['operation'];
        $envelope = ['contract_version' => DelegatedContract::VERSION_2, 'application' => $this->settings->application(), 'operation' => $operation];

        return $envelope + match ($operation) {
            'capabilities' => $this->capabilities(),
            'workspaces' => $this->workspacesPage($actorSubject, $managed, $payload),
            'subjects' => $this->subjectsPage($actorSubject, $managed, $payload),
            'read' => $this->state($actor, $managed, (string) $payload['subject']),
            'update' => $this->update($actor, $managed, $payload),
            default => throw new DelegatedAccessException('invalid_request', 422),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        return ['controls' => [
            'application_admin' => false,
            'workspace_roles' => array_map(
                static fn (WorkspaceRole $role): array => ['id' => $role->value, 'label' => $role->label()],
                WorkspaceRole::cases(),
            ),
            'provisioning' => true,
        ]];
    }

    /**
     * @param  array<int, Workspace>  $managed
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function workspacesPage(string $actorSubject, array $managed, array $payload): array
    {
        $after = $this->after($actorSubject, 'workspaces', $payload);
        $limit = (int) ($payload['limit'] ?? self::PAGE_SIZE);
        $remaining = array_values(array_filter($managed, static fn (Workspace $workspace): bool => $workspace->getKey() > $after));
        $page = array_slice($remaining, 0, $limit);

        return [
            'workspaces' => array_map(static fn (Workspace $workspace): array => [
                'id' => $workspace->public_id,
                // The contract bounds labels to 250 characters; a workspace name may be longer.
                'label' => Str::limit($workspace->name, 250, ''),
            ], $page),
            'next_cursor' => count($remaining) > $limit ? $this->cursor($actorSubject, 'workspaces', (int) $page[array_key_last($page)]->getKey()) : null,
        ];
    }

    /**
     * People bound under this provider who belong to a workspace the actor manages.
     *
     * @param  array<int, Workspace>  $managed
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function subjectsPage(string $actorSubject, array $managed, array $payload): array
    {
        $after = $this->after($actorSubject, 'subjects', $payload);
        $limit = (int) ($payload['limit'] ?? self::PAGE_SIZE);
        $workspaceIds = array_keys($managed);

        $bindings = IdentityBinding::query()
            ->with('user')
            ->where('issuer', $this->settings->bindingIssuer())
            ->whereHas('user.workspaceMemberships', static fn (Builder $memberships): Builder => $memberships->whereIn('workspace_id', $workspaceIds))
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $page = $bindings->take($limit);

        return [
            'subjects' => $page->map(static fn (IdentityBinding $binding): array => [
                'subject' => $binding->subject,
                'label' => Str::limit((string) $binding->user?->name, 250, '') ?: $binding->subject,
            ])->values()->all(),
            'next_cursor' => $bindings->count() > $limit ? $this->cursor($actorSubject, 'subjects', (int) $page->last()?->getKey()) : null,
        ];
    }

    /**
     * The target's access as the actor may see it.
     *
     * @param  array<int, Workspace>  $managed
     * @return array<string, mixed>
     */
    private function state(User $actor, array $managed, string $subject): array
    {
        $target = $this->boundUser($subject);

        if (! $target instanceof User) {
            return [
                'subject' => $subject,
                'provisioned' => false,
                'revision' => null,
                'access' => null,
                'allowed_edits' => ['application_admin' => false, 'workspaces' => false, 'provision' => true],
            ];
        }

        $memberships = $this->visibleMemberships($target, $managed);
        $actorRoles = $this->actorRoles($actor, $managed);

        return [
            'subject' => $subject,
            'provisioned' => true,
            'revision' => self::revision($target, $memberships),
            'access' => [
                'application_admin' => false,
                'workspaces' => array_map(static fn (WorkspaceMembership $membership): array => [
                    'id' => $managed[$membership->workspace_id]->public_id,
                    'role' => $membership->role->value,
                    'editable' => ($actorRoles[$membership->workspace_id] ?? null) === WorkspaceRole::Owner
                        || $membership->role !== WorkspaceRole::Owner,
                ], $memberships),
            ],
            'allowed_edits' => ['application_admin' => false, 'workspaces' => true, 'provision' => false],
        ];
    }

    /**
     * @param  array<int, Workspace>  $managed
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws DelegatedAccessException
     */
    private function update(User $actor, array $managed, array $payload): array
    {
        $subject = (string) $payload['subject'];
        $access = (array) $payload['access'];

        // This application has no application-wide administrator, and says so in capabilities.
        if (($access['application_admin'] ?? null) !== false) {
            throw new DelegatedAccessException('invalid_request', 422);
        }

        $byPublicId = [];
        foreach ($managed as $workspace) {
            $byPublicId[$workspace->public_id] = $workspace;
        }

        /** @var array<int, WorkspaceRole> $desired workspace id => role */
        $desired = [];
        foreach ((array) $access['workspaces'] as $entry) {
            $workspace = $byPublicId[$entry['id']] ?? null;
            $role = WorkspaceRole::tryFrom((string) $entry['role']);

            if (! $workspace instanceof Workspace) {
                throw new DelegatedAccessException('not_authorized', 403);
            }

            if (! $role instanceof WorkspaceRole) {
                throw new DelegatedAccessException('invalid_request', 422);
            }

            $desired[$workspace->getKey()] = $role;
        }

        // Grants lock each workspace's rows in turn. In workspace order, two requests naming the
        // same workspaces in a different order queue behind each other instead of deadlocking.
        ksort($desired);

        // Provisioning grants at least one membership. Each grant rechecks the actor's authority under
        // the membership locks, so an account is never created on the strength of a stale check.
        if ($payload['expected_revision'] === null && $desired === []) {
            throw new DelegatedAccessException('invalid_request', 422);
        }

        try {
            $payload['expected_revision'] === null
                ? $this->provision($actor, $managed, $subject, $desired, $payload['display_name'] ?? null)
                : $this->apply($actor, $managed, $subject, (string) $payload['expected_revision'], $desired);
        } catch (MembershipChangeRefused $refusal) {
            throw match ($refusal->reason) {
                MembershipChangeRefused::NOT_PERMITTED, MembershipChangeRefused::OWNER_ONLY => new DelegatedAccessException('not_authorized', 403),
                default => new DelegatedAccessException('invalid_request', 422),
            };
        }

        return $this->state($actor, $managed, $subject);
    }

    /**
     * @param  array<int, Workspace>  $managed
     * @param  array<int, WorkspaceRole>  $desired
     *
     * @throws DelegatedAccessException
     * @throws MembershipChangeRefused
     */
    private function provision(User $actor, array $managed, string $subject, array $desired, mixed $displayName): void
    {
        $issuer = $this->settings->bindingIssuer();

        try {
            $this->provisionLocked($actor, $managed, $subject, $desired, $displayName, $issuer);
        } catch (UniqueConstraintViolationException) {
            // Somebody bound the subject first: another provisioning request, or the person's own
            // first sign-in. Either way the provider's view is stale, as for an existing binding.
            throw new DelegatedAccessException('revision_conflict', 409);
        }
    }

    /**
     * @param  array<int, Workspace>  $managed
     * @param  array<int, WorkspaceRole>  $desired
     *
     * @throws DelegatedAccessException
     * @throws MembershipChangeRefused
     */
    private function provisionLocked(User $actor, array $managed, string $subject, array $desired, mixed $displayName, string $issuer): void
    {
        DB::transaction(function () use ($actor, $managed, $subject, $desired, $displayName, $issuer): void {
            if (IdentityBinding::query()->forIssuerSubject($issuer, $subject)->lockForUpdate()->exists()) {
                // Already provisioned: the provider's view is stale, and a null revision is not a
                // licence to overwrite what exists.
                throw new DelegatedAccessException('revision_conflict', 409);
            }

            $email = PendingAccount::email($issuer, $subject);

            // A row with the derived address and no binding is an orphan of a removed binding.
            // Adopting it would be linking an account by address, however derived.
            if (User::query()->where('email', $email)->exists()) {
                throw new DelegatedAccessException('invalid_request', 422);
            }

            $user = User::create([
                'name' => is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : PendingAccount::name('Pending member', $subject),
                'email' => $email,
                'password' => Str::random(64),
            ]);

            IdentityBinding::create([
                'user_id' => $user->getKey(),
                'issuer' => $issuer,
                'subject' => $subject,
                // Written by sign-in, not by provisioning: null means they have not signed in yet.
                'last_seen_at' => null,
            ]);

            $this->audit->record(AuditActor::user($actor), 'identity.user_provisioned', $user, [
                ...self::AUDIT_CONTEXT,
                'issuer' => $issuer,
                'subject' => $subject,
                'workspace_memberships' => count($desired),
            ]);

            foreach ($desired as $workspaceId => $role) {
                $this->members->grant($managed[$workspaceId], $actor, $user, $role, self::AUDIT_CONTEXT);
            }
        });
    }

    /**
     * Compare the revision under the changes' own locks, then add, change and remove memberships.
     *
     * @param  array<int, Workspace>  $managed
     * @param  array<int, WorkspaceRole>  $desired
     *
     * @throws DelegatedAccessException
     * @throws MembershipChangeRefused
     */
    private function apply(User $actor, array $managed, string $subject, string $expectedRevision, array $desired): void
    {
        $target = $this->boundUser($subject);

        if (! $target instanceof User) {
            throw new DelegatedAccessException('not_provisioned', 404);
        }

        DB::transaction(function () use ($actor, $managed, $target, $expectedRevision, $desired): void {
            $workspaceIds = array_keys($managed);

            // The order every membership change takes: owner rows first, then the actor's and the
            // target's rows in id order. Holding them here means nobody can change what the revision
            // describes between comparing it and changing it.
            WorkspaceMembership::query()->whereIn('workspace_id', $workspaceIds)->where('role', WorkspaceRole::Owner->value)
                ->orderBy('workspace_id')->orderBy('id')->lockForUpdate()->get();
            WorkspaceMembership::query()->whereIn('workspace_id', $workspaceIds)->whereIn('user_id', [$actor->getKey(), $target->getKey()])
                ->orderBy('id')->lockForUpdate()->get();

            $current = $this->visibleMemberships($target, $managed);

            if (! hash_equals(self::revision($target, $current), $expectedRevision)) {
                throw new DelegatedAccessException('revision_conflict', 409);
            }

            $existing = [];
            foreach ($current as $membership) {
                $existing[$membership->workspace_id] = $membership;
            }

            foreach ($desired as $workspaceId => $role) {
                $membership = $existing[$workspaceId] ?? null;

                if (! $membership instanceof WorkspaceMembership) {
                    $this->members->grant($managed[$workspaceId], $actor, $target, $role, self::AUDIT_CONTEXT);
                } elseif ($membership->role !== $role) {
                    $this->members->changeRole($managed[$workspaceId], $actor, $membership, $role, self::AUDIT_CONTEXT);
                }
            }

            foreach ($existing as $workspaceId => $membership) {
                if (! array_key_exists($workspaceId, $desired)) {
                    $this->members->remove($managed[$workspaceId], $actor, $membership, self::AUDIT_CONTEXT);
                }
            }
        });
    }

    /**
     * Workspaces the actor owns or administers, keyed by id, in id order.
     *
     * @return array<int, Workspace>
     */
    private function managedWorkspaces(User $actor): array
    {
        $workspaces = [];

        foreach (Workspace::query()
            ->whereHas('memberships', static fn (Builder $memberships): Builder => $memberships
                ->where('user_id', $actor->getKey())
                ->whereIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value]))
            ->orderBy('id')
            ->get() as $workspace) {
            $workspaces[$workspace->getKey()] = $workspace;
        }

        return $workspaces;
    }

    /**
     * @param  array<int, Workspace>  $managed
     * @return array<int, WorkspaceRole> workspace id => the actor's role
     */
    private function actorRoles(User $actor, array $managed): array
    {
        return WorkspaceMembership::query()
            ->whereIn('workspace_id', array_keys($managed))
            ->where('user_id', $actor->getKey())
            ->get()
            ->mapWithKeys(static fn (WorkspaceMembership $membership): array => [$membership->workspace_id => $membership->role])
            ->all();
    }

    /**
     * @param  array<int, Workspace>  $managed
     * @return list<WorkspaceMembership>
     */
    private function visibleMemberships(User $target, array $managed): array
    {
        return WorkspaceMembership::query()
            ->whereIn('workspace_id', array_keys($managed))
            ->where('user_id', $target->getKey())
            ->orderBy('workspace_id')
            ->get()
            ->all();
    }

    private function boundUser(string $subject): ?User
    {
        $binding = IdentityBinding::query()->forIssuerSubject($this->settings->bindingIssuer(), $subject)->with('user')->first();

        return $binding?->user instanceof User ? $binding->user : null;
    }

    /**
     * A digest of exactly what a read showed: the account and its visible memberships.
     *
     * @param  list<WorkspaceMembership>  $memberships
     */
    private static function revision(User $target, array $memberships): string
    {
        return hash('sha256', (string) json_encode([
            'user' => $target->getKey(),
            'memberships' => array_map(static fn (WorkspaceMembership $membership): array => [$membership->workspace_id, $membership->role->value], $memberships),
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws DelegatedAccessException
     */
    private function after(string $actorSubject, string $operation, array $payload): int
    {
        if (! isset($payload['cursor'])) {
            return 0;
        }

        try {
            $cursor = json_decode($this->encrypter->decryptString((string) $payload['cursor']), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DelegatedAccessException('invalid_cursor', 422);
        }

        // Bound to the actor and the operation, so a cursor cannot be replayed by somebody else or
        // against another listing. It carries the last id shown, never an offset: a row removed
        // between pages must not make the next page skip one.
        if (! is_array($cursor) || ($cursor['actor'] ?? null) !== $actorSubject || ($cursor['operation'] ?? null) !== $operation
            || ! is_int($cursor['after'] ?? null) || $cursor['after'] < 0) {
            throw new DelegatedAccessException('invalid_cursor', 422);
        }

        return $cursor['after'];
    }

    private function cursor(string $actorSubject, string $operation, int $after): string
    {
        return $this->encrypter->encryptString((string) json_encode(['actor' => $actorSubject, 'operation' => $operation, 'after' => $after]));
    }
}
