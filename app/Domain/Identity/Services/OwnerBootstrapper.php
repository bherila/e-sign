<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisions the first owner of a workspace.
 *
 * This is the only place in the application that grants `owner`, and it only ever runs from
 * an explicit console command. Nothing here reacts to a login, an email address, or a
 * directory grant: an operator names the issuer and subject (or an existing local user) and
 * the workspace, and that is the whole input. "First person to log in becomes an admin" is
 * not a code path that exists.
 *
 * Every run is one transaction and is idempotent. A rerun with the same arguments finds the
 * workspace, the binding, and the membership already in place, records no audit event, and
 * reports the existing state.
 */
class OwnerBootstrapper
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Provision an owner identified by an identity-provider (issuer, subject) tuple.
     *
     * The local user row is created only as somewhere to hang the binding, with a
     * placeholder name and an unroutable placeholder address. The real name and email
     * arrive from the provider at first sign-in (issue #12). The address is a `.invalid`
     * value derived from the tuple: deterministic so reruns are stable, and reserved by
     * RFC 2606 so nothing can ever mail it or match it against a provider account.
     */
    public function bootstrapWithBinding(
        string $issuer,
        string $subject,
        string $slug,
        ?string $name,
        AuditActor $actor,
    ): BootstrapOutcome {
        return $this->run(
            $slug,
            $name,
            $actor,
            ['mode' => 'sso', 'issuer' => $issuer, 'subject' => $subject],
            function (array &$changes) use ($issuer, $subject): array {
                $binding = IdentityBinding::query()
                    ->forIssuerSubject($issuer, $subject)
                    ->first();

                if ($binding !== null) {
                    $user = $binding->user;

                    if (! $user instanceof User) {
                        throw new RuntimeException('The identity binding references a user row that no longer exists.');
                    }

                    return [$user, $binding];
                }

                $placeholderEmail = $this->placeholderEmail($issuer, $subject);

                // A binding is the only thing that links this tuple to a user row, and it
                // is gone. If a row still holds the derived placeholder address, an earlier
                // binding for this tuple was revoked and its orphan row is still here. Say
                // so and stop: silently adopting a row found by its address would be
                // account linking by email, however derived that address is.
                if (User::where('email', $placeholderEmail)->exists()) {
                    // The message carries its remedy after a newline; the command prints
                    // the two parts separately so neither gets wrapped into nonsense.
                    throw new RuntimeException(
                        "A user row provisioned for {$issuer} / {$subject} exists, but its identity binding was removed.\n".
                        'Delete that user row if it holds nothing worth keeping, or bind it again directly. '.
                        'Provisioning never adopts a user row it found by address.'
                    );
                }

                $user = User::create([
                    'name' => $this->placeholderName($subject),
                    'email' => $placeholderEmail,
                    'password' => Str::random(64),
                ]);
                $changes[] = "created placeholder user #{$user->getKey()} for the identity binding";

                $binding = IdentityBinding::create([
                    'user_id' => $user->getKey(),
                    'issuer' => $issuer,
                    'subject' => $subject,
                    // Written by the login flow, not by provisioning. A null value is the
                    // runbook's signal that the owner has not signed in yet.
                    'last_seen_at' => null,
                ]);
                $changes[] = "bound identity {$issuer} / {$subject} to user #{$user->getKey()}";

                return [$user, $binding];
            },
        );
    }

    /**
     * Provision an owner who already has a local user row (standalone installations).
     *
     * The caller resolves the user; this method never looks one up by email and never
     * creates one, so there is no path from "an address was typed" to "an account exists".
     */
    public function bootstrapLocalUser(User $user, string $slug, ?string $name, AuditActor $actor): BootstrapOutcome
    {
        return $this->run(
            $slug,
            $name,
            $actor,
            ['mode' => 'standalone'],
            static fn (array &$changes): array => [$user, null],
        );
    }

    /**
     * @param  array<string, mixed>  $auditContext
     * @param  Closure(list<string>): array{0: User, 1: IdentityBinding|null}  $resolveUser
     */
    private function run(
        string $slug,
        ?string $name,
        AuditActor $actor,
        array $auditContext,
        Closure $resolveUser,
    ): BootstrapOutcome {
        return DB::transaction(function () use ($slug, $name, $actor, $auditContext, $resolveUser): BootstrapOutcome {
            /** @var list<string> $changes */
            $changes = [];
            /** @var list<string> $notes */
            $notes = [];

            $workspace = $this->resolveWorkspace($slug, $name, $changes, $notes);

            [$user, $binding] = $resolveUser($changes);

            $membership = $this->resolveOwnerMembership($workspace, $user, $changes);

            $outcome = new BootstrapOutcome($workspace, $user, $binding, $membership, $changes, $notes);

            // A no-op rerun writes nothing at all, audit row included. An audit trail that
            // grows every time someone checks the state is a worse trail.
            if ($outcome->changed()) {
                $this->audit->record($actor, 'identity.owner_bootstrapped', $workspace, [
                    ...$auditContext,
                    'workspace_slug' => $workspace->slug,
                    'workspace_public_id' => $workspace->public_id,
                    'user_id' => $user->getKey(),
                    'role' => WorkspaceRole::Owner->value,
                    'changes' => $changes,
                ]);
            }

            return $outcome;
        });
    }

    /**
     * @param  list<string>  $changes
     * @param  list<string>  $notes
     */
    private function resolveWorkspace(string $slug, ?string $name, array &$changes, array &$notes): Workspace
    {
        $existing = Workspace::withTrashed()->where('slug', $slug)->first();

        if ($existing !== null && $existing->trashed()) {
            throw new RuntimeException(
                "Workspace '{$slug}' exists but is deleted.\n".
                'Restore it, or choose a different --workspace slug. A deleted workspace still has its envelopes and evidence attached, '.
                'so provisioning never reuses one.'
            );
        }

        if ($existing !== null) {
            if ($name !== null && $name !== $existing->name) {
                $notes[] = "workspace '{$slug}' is named '{$existing->name}'; --name was not applied because bootstrap never renames an existing workspace";
            }

            return $existing;
        }

        $workspace = Workspace::create([
            'name' => $name ?? Str::headline(str_replace('-', ' ', $slug)),
            'slug' => $slug,
        ]);
        $changes[] = "created workspace '{$slug}'";

        return $workspace;
    }

    /**
     * @param  list<string>  $changes
     */
    private function resolveOwnerMembership(Workspace $workspace, User $user, array &$changes): WorkspaceMembership
    {
        $membership = $workspace->membershipFor($user);

        if ($membership === null) {
            $membership = WorkspaceMembership::create([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $user->getKey(),
                'role' => WorkspaceRole::Owner,
            ]);
            $changes[] = "granted owner in '{$workspace->slug}' to user #{$user->getKey()}";

            return $membership;
        }

        if ($membership->role !== WorkspaceRole::Owner) {
            $previous = $membership->role->value;
            $membership->role = WorkspaceRole::Owner;
            $membership->save();
            $changes[] = "promoted user #{$user->getKey()} in '{$workspace->slug}' from {$previous} to owner";
        }

        return $membership;
    }

    private function placeholderName(string $subject): string
    {
        return 'Pending owner ('.Str::limit($subject, 40, '…').')';
    }

    private function placeholderEmail(string $issuer, string $subject): string
    {
        // `.invalid` is reserved by RFC 2606 and resolves nowhere, so this address can never
        // be mailed and can never be mistaken for a real one. It is not an account-linking
        // key: the (issuer, subject) binding is.
        return 'sso-'.substr(hash('sha256', $issuer."\0".$subject), 0, 32).'@invalid';
    }
}
