<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions\Models;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\OtpChallenges;
use App\Domain\Signing\Sessions\OtpPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One emailed code. Issuing and verifying belong to {@see OtpChallenges}; this row only
 * remembers what was decided.
 *
 * @property int $id
 * @property string $public_id
 * @property int $recipient_id
 * @property int $envelope_id
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $burned_at
 * @property string $ip_hash
 */
class SigningOtpChallenge extends Model
{
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'purpose',
        'code_hash',
        'expires_at',
        'ip_hash',
    ];

    /** @var list<string> */
    protected $hidden = ['code_hash', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'burned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            if (($challenge->public_id ?? '') === '') {
                $challenge->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<EnvelopeRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EnvelopeRecipient::class, 'recipient_id');
    }

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function isOpen(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->burned_at === null
            && $this->verified_at === null
            && $this->expires_at->greaterThan($now);
    }
}
