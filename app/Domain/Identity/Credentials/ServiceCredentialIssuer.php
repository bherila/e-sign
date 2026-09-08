<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only code that creates, rotates, or revokes a service credential.
 *
 * Three operations, each one transaction, each one audit event:
 *
 *  - **issue** mints a secret, stores its digest, and hands the plaintext back exactly once.
 *  - **rotate** issues a successor and gives the predecessor a deadline instead of killing
 *    it, so a deployed integration can pick the new secret up without a synchronised
 *    restart. Twenty-four hours by default; the predecessor's own expiry is never extended
 *    to accommodate the window.
 *  - **revoke** is immediate and final. There is no un-revoke, because "the secret leaked"
 *    is the only reason anyone runs it.
 *
 * Nothing here reads a plaintext secret back, and no method returns one for a credential
 * that already exists: the plaintext is unrecoverable by construction, not by policy.
 */
class ServiceCredentialIssuer
{
    /**
     * How long a rotated-away secret keeps working by default. Long enough for an operator
     * to redeploy a consumer during business hours; short enough that a rotation prompted by
     * a suspected leak is not a week-long window. An operator who is rotating because a
     * secret leaked passes `--overlap=0` (or revokes).
     */
    public const DEFAULT_ROTATION_OVERLAP_HOURS = 24;

    /**
     * The unique index on `prefix` is the real guard; this is only how many collisions we
     * are willing to shrug off before concluding something is wrong with the entropy source.
     */
    private const PREFIX_ATTEMPTS = 5;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Issue a new credential in a workspace.
     *
     * @param  iterable<mixed>  $scopes  Untrusted scope strings; resolved through
     *                                   Scope::fromValues(), which rejects the whole list if
     *                                   any member is unknown.
     *
     * @throws UnknownScope
     * @throws InvalidArgumentException
     */
    public function issue(
        Workspace $workspace,
        string $label,
        iterable $scopes,
        AuditActor $actor,
        ?DateTimeInterface $expiresAt = null,
    ): IssuedServiceCredential {
        $label = $this->validLabel($label);
        $granted = $this->validScopes($scopes);
        $expiry = $this->futureExpiry($expiresAt);

        $this->assertWorkspaceUsable($workspace);

        return DB::transaction(function () use ($workspace, $label, $granted, $expiry, $actor): IssuedServiceCredential {
            $issued = $this->persist($workspace, $label, $granted, $expiry, null);

            $this->audit->record($actor, 'identity.service_credential_issued', $issued->credential, [
                'workspace_id' => $workspace->getKey(),
                'workspace_public_id' => $workspace->public_id,
                'label' => $label,
                'credential_prefix' => $issued->credential->prefix,
                'scopes' => $issued->credential->scopes,
                'expires_at' => $expiry?->toIso8601String(),
            ]);

            return $issued;
        });
    }

    /**
     * Replace a credential's secret, leaving the old one usable until the overlap expires.
     *
     * The successor inherits the predecessor's label, workspace, scopes, and expiry unless
     * `$expiresAt` says otherwise. Inheriting the expiry matters: a credential issued to
     * expire on a fixed date must not become immortal because someone rotated it.
     *
     * @param  CarbonInterval|null  $overlap  How much longer the old secret works. Zero cuts
     *                                        it over immediately.
     *
     * @throws RuntimeException when the credential is revoked or already expired; those are
     *                          not rotated, they are replaced by a fresh issue.
     */
    public function rotate(
        ServiceCredential $credential,
        AuditActor $actor,
        ?CarbonInterval $overlap = null,
        ?DateTimeInterface $expiresAt = null,
    ): IssuedServiceCredential {
        if ($credential->isRevoked()) {
            throw new RuntimeException(
                "Credential {$credential->prefix} is revoked.\n".
                'A revoked credential is never brought back, not even under a new secret. Issue a new credential instead.'
            );
        }

        if ($credential->isExpired()) {
            throw new RuntimeException(
                "Credential {$credential->prefix} expired at {$credential->expires_at?->toDateTimeString()}.\n".
                'There is nothing left to overlap with. Issue a new credential instead.'
            );
        }

        $workspace = $credential->workspace;

        if (! $workspace instanceof Workspace) {
            throw new RuntimeException("Credential {$credential->prefix} references a workspace that no longer exists.");
        }

        $this->assertWorkspaceUsable($workspace);

        $overlap = $this->validOverlap($overlap);
        $successorExpiry = $expiresAt === null
            ? $credential->expires_at
            : $this->futureExpiry($expiresAt);

        $now = CarbonImmutable::instance(Carbon::now());
        $overlapEnds = $now->add($overlap);

        // Never extend a credential past the expiry it was issued with: the overlap window
        // is a grace period inside its existing life, not a renewal of it.
        if ($credential->expires_at !== null && $credential->expires_at->lessThan($overlapEnds)) {
            $overlapEnds = $credential->expires_at;
        }

        return DB::transaction(function () use (
            $credential,
            $workspace,
            $successorExpiry,
            $overlapEnds,
            $overlap,
            $actor,
        ): IssuedServiceCredential {
            $issued = $this->persist(
                $workspace,
                $credential->label,
                $credential->grantedScopes(),
                $successorExpiry,
                $credential,
            );

            $credential->expires_at = $overlapEnds;
            $credential->save();

            $this->audit->record($actor, 'identity.service_credential_rotated', $issued->credential, [
                'workspace_id' => $workspace->getKey(),
                'workspace_public_id' => $workspace->public_id,
                'label' => $credential->label,
                'credential_prefix' => $issued->credential->prefix,
                'rotated_from_prefix' => $credential->prefix,
                'scopes' => $issued->credential->scopes,
                'expires_at' => $successorExpiry?->toIso8601String(),
                'overlap_seconds' => (int) $overlap->totalSeconds,
                'previous_secret_expires_at' => $overlapEnds->toIso8601String(),
            ]);

            return new IssuedServiceCredential($issued->credential, $issued->secret, $credential);
        });
    }

    /**
     * Revoke immediately.
     *
     * Revoking an already-revoked credential is a no-op and writes no audit event: a trail
     * that grows every time an operator makes sure is a worse trail.
     */
    public function revoke(ServiceCredential $credential, AuditActor $actor, ?string $reason = null): ServiceCredential
    {
        if ($credential->isRevoked()) {
            return $credential;
        }

        return DB::transaction(function () use ($credential, $actor, $reason): ServiceCredential {
            $credential->revoked_at = CarbonImmutable::instance(Carbon::now());
            $credential->save();

            $this->audit->record($actor, 'identity.service_credential_revoked', $credential, [
                'workspace_id' => $credential->workspace_id,
                'label' => $credential->label,
                'credential_prefix' => $credential->prefix,
                'scopes' => $credential->scopes,
                'reason' => $reason,
            ]);

            return $credential;
        });
    }

    /**
     * @param  list<Scope>  $granted
     */
    private function persist(
        Workspace $workspace,
        string $label,
        array $granted,
        ?CarbonImmutable $expiry,
        ?ServiceCredential $rotatedFrom,
    ): IssuedServiceCredential {
        $secret = $this->mintUniqueSecret();

        $credential = new ServiceCredential;
        // Force-filled rather than mass-assigned: the secret columns are deliberately absent
        // from $fillable so no request payload can ever reach them.
        $credential->forceFill([
            'workspace_id' => $workspace->getKey(),
            'label' => $label,
            'prefix' => $secret->prefix,
            'secret_salt' => $secret->salt,
            'secret_hash' => $secret->hash,
            'scopes' => array_map(static fn (Scope $scope): string => $scope->value, $granted),
            'expires_at' => $expiry,
            'rotated_from_id' => $rotatedFrom?->getKey(),
        ]);
        $credential->save();
        $credential->setRelation('workspace', $workspace);

        return new IssuedServiceCredential($credential, $secret->plaintext, $rotatedFrom);
    }

    private function mintUniqueSecret(): CredentialSecret
    {
        for ($attempt = 0; $attempt < self::PREFIX_ATTEMPTS; $attempt++) {
            $secret = CredentialSecret::mint();

            if (! ServiceCredential::query()->forPrefix($secret->prefix)->exists()) {
                return $secret;
            }
        }

        throw new RuntimeException(
            'Could not mint a unique credential prefix after '.self::PREFIX_ATTEMPTS.' attempts. '.
            'Check the random source before retrying.'
        );
    }

    private function validLabel(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            throw new InvalidArgumentException(
                "A credential needs a label.\n".
                'It is how an operator tells one integration from another when deciding what to revoke.'
            );
        }

        if (mb_strlen($label) > 191) {
            throw new InvalidArgumentException('A credential label may be at most 191 characters.');
        }

        return $label;
    }

    /**
     * @param  iterable<mixed>  $scopes
     * @return list<Scope>
     */
    private function validScopes(iterable $scopes): array
    {
        $granted = Scope::fromValues($scopes);

        if ($granted === []) {
            throw new InvalidArgumentException(
                "A credential needs at least one scope.\n".
                'A credential with no scopes can authenticate and then do nothing, which is a support ticket, not a security control. '.
                'Known scopes: '.implode(', ', Scope::values()).'.'
            );
        }

        return $granted;
    }

    private function futureExpiry(?DateTimeInterface $expiresAt): ?CarbonImmutable
    {
        if ($expiresAt === null) {
            return null;
        }

        $expiry = CarbonImmutable::instance(Carbon::instance($expiresAt));

        if ($expiry->lessThanOrEqualTo(Carbon::now())) {
            throw new InvalidArgumentException(
                "The requested expiry {$expiry->toIso8601String()} is in the past.\n".
                'Issuing a credential that is already expired would look like success and authenticate nothing.'
            );
        }

        return $expiry;
    }

    private function validOverlap(?CarbonInterval $overlap): CarbonInterval
    {
        $overlap ??= CarbonInterval::hours(self::DEFAULT_ROTATION_OVERLAP_HOURS);

        if ($overlap->totalSeconds < 0) {
            throw new InvalidArgumentException('A rotation overlap cannot be negative.');
        }

        return $overlap;
    }

    private function assertWorkspaceUsable(Workspace $workspace): void
    {
        if ($workspace->trashed()) {
            throw new RuntimeException(
                "Workspace '{$workspace->slug}' is deleted.\n".
                'Restore it before issuing or rotating credentials in it.'
            );
        }
    }
}
