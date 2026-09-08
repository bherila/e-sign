<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions\Models;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\SigningSessionManager;
use App\Domain\Signing\Sessions\SigningVerification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One guest's authorized period on one envelope.
 *
 * `public_id` is quoted verbatim as `recipient_attestations.session_ref`, which makes it the
 * idempotency key for an acceptance (docs/ARCHITECTURE.md invariant 7). It is a ULID and not
 * the cookie value: the cookie is a bearer credential and must not appear in an evidence
 * record, and the evidence record has to stay readable after the credential is gone.
 *
 * Transitions belong to {@see SigningSessionManager}. Nothing here extends its own life.
 *
 * @property int $id
 * @property string $public_id
 * @property int $recipient_id
 * @property int $envelope_id
 * @property int|null $invitation_id
 * @property string $session_token_hash
 * @property string $ip_hash
 * @property string $user_agent_hash
 * @property SigningVerification $verification_method
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $ended_reason
 * @property string|null $return_url
 */
class SigningSession extends Model
{
    protected $fillable = [
        'recipient_id',
        'envelope_id',
        'invitation_id',
        'session_token_hash',
        'ip_hash',
        'user_agent_hash',
        'verification_method',
        'started_at',
        'expires_at',
        'last_seen_at',
        'return_url',
    ];

    /** @var list<string> */
    protected $hidden = ['session_token_hash', 'ip_hash', 'user_agent_hash'];

    protected function casts(): array
    {
        return [
            'verification_method' => SigningVerification::class,
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if (($session->public_id ?? '') === '') {
                $session->public_id = (string) Str::ulid();
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

    /** @return BelongsTo<RecipientInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(RecipientInvitation::class, 'invitation_id');
    }

    public function isLive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->ended_at === null && $this->expires_at->greaterThan($now);
    }

    /**
     * True when this session is the one the URL is talking about.
     *
     * Both halves are checked, not just the envelope: a workspace member who is also a
     * recipient on two envelopes, or a shared browser, must not be able to act as a
     * different party by editing a path. A session that does not match is refused with 403
     * rather than silently re-scoped.
     */
    public function isScopedTo(Envelope $envelope, EnvelopeRecipient $recipient): bool
    {
        return $this->envelope_id === $envelope->getKey()
            && $this->recipient_id === $recipient->getKey();
    }
}
