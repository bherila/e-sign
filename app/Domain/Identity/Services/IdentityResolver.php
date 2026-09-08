<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Models\IdentityBinding;
use App\Models\User;
use BWH\Auth\OAuth\OAuthIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a validated provider identity into the local user it belongs to.
 *
 * The lookup is `(issuer, subject)` and nothing else. There is no branch that consults the
 * email address, because the moment one exists an identity provider can hand this
 * application somebody else's account by reporting their address — and an address is
 * exactly the field a provider is least able to promise is stable or exclusive. Two
 * subjects that report the same address therefore produce two users, which is why
 * `users.email` carries no unique index.
 *
 * A subject with no binding is admitted and given nothing: a user row, a binding, and no
 * workspace membership. That is the whole of "first login is not an administrator" —
 * authority comes from `esign:bootstrap-owner` or from an existing owner, so there is no
 * code here that could grant it even by accident.
 */
class IdentityResolver
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * The local user for this provider identity, provisioning one if the subject is new.
     */
    public function resolve(OAuthIdentity $identity): User
    {
        try {
            return DB::transaction(fn (): User => $this->resolveWithinTransaction($identity));
        } catch (QueryException $exception) {
            // Two first logins for the same subject at once: one insert wins the unique
            // index on (issuer, subject) and the other lands here. Re-reading is correct
            // and is the reason that index exists — the loser must find the winner's row,
            // never create a second user for the same person.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $binding = IdentityBinding::query()
                ->forIssuerSubject($identity->provider, $identity->subject)
                ->first();

            if ($binding === null) {
                throw $exception;
            }

            return $this->touch($binding, $identity);
        }
    }

    private function resolveWithinTransaction(OAuthIdentity $identity): User
    {
        $binding = IdentityBinding::query()
            ->forIssuerSubject($identity->provider, $identity->subject)
            ->lockForUpdate()
            ->first();

        if ($binding !== null) {
            return $this->touch($binding, $identity);
        }

        // Contact data only. It is written here so operators and notifications have
        // something to show, and read by nothing that decides who this person is.
        $user = User::create([
            'name' => $identity->name,
            'email' => $identity->email,
            // No local credential exists for an SSO account, and none can be arrived at:
            // the value is random, never shown, and standalone login refuses any user that
            // has an identity binding regardless.
            'password' => Str::random(64),
        ]);

        $binding = IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => $identity->provider,
            'subject' => $identity->subject,
            'last_seen_at' => now(),
        ]);

        // Deliberately no workspace membership. An admitted person with no membership row
        // can authenticate and then see nothing, which is the separation docs/HANDOFF.md §5
        // requires between a directory grant and workspace authority.
        $this->audit->record(
            AuditActor::system('oauth callback'),
            'identity.user_provisioned',
            $user,
            [
                'issuer' => $binding->issuer,
                'subject' => $binding->subject,
                'workspace_memberships' => 0,
            ],
        );

        return $user;
    }

    /**
     * Refresh what the provider owns and stamp the sign-in.
     *
     * Name and email are the provider's to change; a bootstrapped owner arrives here with a
     * placeholder name and an unroutable address and leaves with their real ones. The
     * binding tuple itself is never rewritten.
     */
    private function touch(IdentityBinding $binding, OAuthIdentity $identity): User
    {
        $user = $binding->user;

        if (! $user instanceof User) {
            throw new RuntimeException(
                'The identity binding for '.$binding->issuer.' / '.$binding->subject.
                ' references a user row that no longer exists.',
            );
        }

        $user->forceFill([
            'name' => $identity->name,
            'email' => $identity->email,
        ])->save();

        $binding->forceFill(['last_seen_at' => now()])->save();

        return $user;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }
}
