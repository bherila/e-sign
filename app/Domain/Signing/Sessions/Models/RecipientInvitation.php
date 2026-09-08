<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions\Models;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\InvitationIssuer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One invitation credential, as a row. The token itself is not here and never was — only
 * its SHA-256 verifier.
 *
 * The model answers whether the credential is live and nothing else; issuing, revoking, and
 * reissuing belong to {@see InvitationIssuer}, so there is one place that decides what
 * supersedes what.
 *
 * @property int $id
 * @property string $public_id
 * @property int $recipient_id
 * @property int $envelope_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $issued_by
 */
class RecipientInvitation extends Model
{
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'token_hash',
        'expires_at',
        'issued_by',
    ];

    /**
     * Never serialised anywhere, but stating it means a careless `toArray()` on a debugging
     * branch cannot put a verifier in a response body.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            if (($invitation->public_id ?? '') === '') {
                $invitation->public_id = (string) Str::ulid();
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

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Not revoked, not consumed, not expired.
     *
     * All three are checked because all three can be true at once and they mean different
     * things to whoever is asking. `reason()` says which one refused.
     */
    public function isLive(?CarbonImmutable $now = null): bool
    {
        return $this->refusalReason($now) === null;
    }

    /**
     * Why this credential cannot be used, or null when it can.
     *
     * A stable machine-readable string rather than a sentence: it is recorded in the audit
     * trail and matched in tests, and the wording a person sees is chosen by the view. What
     * the *guest* is told is deliberately coarser — see
     * App\Http\Controllers\Signing\SigningLandingController.
     */
    public function refusalReason(?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();

        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->consumed_at !== null) {
            return 'consumed';
        }

        if ($this->expires_at->lessThanOrEqualTo($now)) {
            return 'expired';
        }

        return null;
    }

    /**
     * Invitations that have not been revoked or consumed and have not run out of time.
     *
     * @param  Builder<RecipientInvitation>  $query
     * @return Builder<RecipientInvitation>
     */
    public function scopeLive(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now ?? CarbonImmutable::now());
    }
}
