<?php

declare(strict_types=1);

namespace App\Domain\Signing\Models;

use App\Domain\Signing\Envelopes\VerificationMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One person's assent, recorded once and never touched again.
 *
 * The row *is* the acceptance. Every fact it binds is a digest rather than a reference, so
 * nothing it describes can move underneath it: the document bytes, the field schema, the
 * material values, the consent version that was displayed, and the session it was given in
 * (docs/ARCHITECTURE.md invariant 2).
 *
 * Append-only is enforced here, not in a trigger, because triggers do not behave identically
 * on SQLite, MySQL, and MariaDB — the same reasoning `document_revisions` and
 * `esign_audit_events` record. Model-level enforcement stops the application from rewriting
 * its own evidence; a deployment that needs the guarantee against a compromised application
 * grants its database user INSERT and SELECT on this table and nothing else. A hash chain in
 * an administrator-writable database is not an independent witness, and docs/HANDOFF.md
 * section 8 says so; off-host checkpoints are a separate deliverable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $recipient_id
 * @property int $envelope_id
 * @property string $document_sha256
 * @property string $field_schema_sha256
 * @property string $material_values_sha256
 * @property string $consent_policy_version
 * @property string $session_ref
 * @property CarbonImmutable $accepted_at
 * @property VerificationMethod $verification_method
 * @property array<string, mixed> $client_evidence
 * @property string|null $prev_attestation_sha256
 * @property string $attestation_sha256
 */
class RecipientAttestation extends Model
{
    /** Written once; there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'document_sha256',
        'field_schema_sha256',
        'material_values_sha256',
        'consent_policy_version',
        'session_ref',
        'accepted_at',
        'verification_method',
        'client_evidence',
        'prev_attestation_sha256',
        'attestation_sha256',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
            'verification_method' => VerificationMethod::class,
            'client_evidence' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attestation): void {
            if (($attestation->public_id ?? '') === '') {
                $attestation->public_id = (string) Str::ulid();
            }
        });

        static::updating(static function (self $attestation): never {
            throw new RuntimeException(
                'recipient_attestations is append-only; an acceptance cannot be edited. '
                .'A changed agreement requires a fresh review and a fresh acceptance.',
            );
        });

        static::deleting(static function (self $attestation): never {
            throw new RuntimeException(
                'recipient_attestations is append-only; an acceptance cannot be deleted.',
            );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<EnvelopeRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EnvelopeRecipient::class, 'recipient_id');
    }
}
