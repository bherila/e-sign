<?php

declare(strict_types=1);

namespace App\Domain\Signing\Models;

use App\Domain\Signing\Envelopes\MaterialValues;
use App\Domain\Signing\Envelopes\ValueSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current value of one field of one envelope.
 *
 * Mutable on purpose, and only until it freezes. What was agreed to is not read back from
 * here — it is bound by `recipient_attestations.material_values_sha256`, which never
 * changes. Keeping the working value editable and the evidence immutable is what lets a
 * sender fix a typo before anybody signs without inventing a version history nobody asked
 * for.
 *
 * `value` is stored as JSON rather than as text so a checkbox stays a boolean and a date
 * stays a string; `value_sha256` is the digest of the canonical encoding of that JSON, which
 * is what the material digest is built from
 * ({@see MaterialValues}).
 *
 * @property int $id
 * @property int $envelope_id
 * @property int|null $recipient_id
 * @property string $schema_field_id
 * @property mixed $value
 * @property string $value_sha256
 * @property ValueSource $set_by
 * @property CarbonImmutable|null $frozen_at
 */
class EnvelopeFieldValue extends Model
{
    protected $fillable = [
        'envelope_id',
        'recipient_id',
        'schema_field_id',
        'value',
        'value_sha256',
        'set_by',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'set_by' => ValueSource::class,
            'frozen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }
}
