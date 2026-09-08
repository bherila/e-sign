<?php

declare(strict_types=1);

namespace App\Domain\Signing\Models;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Signing\Envelopes\RecipientState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One party on one envelope.
 *
 * `schema_recipient_id` is the handle the copied field schema uses, and it is how a field
 * finds its owner. Ownership is never resolved by signing order and never by email address
 * (AGENTS.md; docs/HANDOFF.md section 2 records that the first consumer's provider ties
 * fields to recipient ids for exactly this reason).
 *
 * As with {@see Envelope}, the model holds no transitions. `version` is this row's own
 * compare-and-swap token so two recipients acting simultaneously do not contend on one
 * counter, and only the state machine advances it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $envelope_id
 * @property string $schema_recipient_id
 * @property string $name
 * @property string $email
 * @property int $order_index
 * @property RecipientState $state
 * @property int $version
 * @property CarbonImmutable|null $signed_at
 * @property CarbonImmutable|null $declined_at
 * @property string|null $decline_reason
 * @property array<string, mixed> $identity_snapshot
 */
class EnvelopeRecipient extends Model
{
    protected $fillable = [
        'envelope_id',
        'schema_recipient_id',
        'name',
        'email',
        'order_index',
        'state',
        'version',
        'identity_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'state' => RecipientState::class,
            'order_index' => 'integer',
            'version' => 'integer',
            'identity_snapshot' => 'array',
            'signed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $recipient): void {
            if (($recipient->public_id ?? '') === '') {
                $recipient->public_id = (string) Str::ulid();
            }
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

    /** @return HasMany<EnvelopeFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(EnvelopeFieldValue::class, 'recipient_id');
    }

    /** @return HasMany<RecipientAttestation, $this> */
    public function attestations(): HasMany
    {
        return $this->hasMany(RecipientAttestation::class, 'recipient_id')->orderBy('id');
    }

    /**
     * The fields this recipient owns, read from the envelope's copied schema.
     *
     * @return list<FieldDefinition>
     */
    public function schemaFields(Envelope $envelope): array
    {
        return $envelope->fieldSchema()->fieldsFor($this->schema_recipient_id);
    }
}
