<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Models;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Identity\Models\Workspace;
use Database\Factories\OutboundMailFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One transactional message, and everything known about what happened to it.
 *
 * State transitions go through the methods here rather than mass assignment, because every
 * one of them has to move `state_changed_at` with it — an outbox whose state and timestamp
 * can disagree cannot answer "how long has this been stuck", which is the only question the
 * backlog probe asks.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $workspace_id
 * @property MailKind $kind
 * @property string $to_email
 * @property string|null $to_name
 * @property string $subject
 * @property array<string, mixed> $context
 * @property string|null $message_id
 * @property MailState $state
 * @property Carbon $state_changed_at
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $mailer
 * @property string|null $related_type
 * @property string|null $related_id
 * @property int|null $resent_from_id
 */
class OutboundMail extends Model
{
    /** @use HasFactory<OutboundMailFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'kind',
        'to_email',
        'to_name',
        'subject',
        'context',
        'state',
        'state_changed_at',
        'related_type',
        'related_id',
        'resent_from_id',
    ];

    /**
     * Mirrors the column default so a freshly created instance reports 0 attempts rather
     * than null before it has been reloaded. The database default stays in the migration;
     * this is about the in-memory model being honest immediately.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => MailKind::class,
            'state' => MailState::class,
            'context' => 'array',
            'state_changed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $mail): void {
            if (($mail->public_id ?? '') === '') {
                $mail->public_id = (string) Str::ulid();
            }
        });
    }

    /** Bind route parameters on the public identifier, never the autoincrement id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<OutboundMailEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OutboundMailEvent::class);
    }

    /**
     * Whatever caused this message. Unconstrained on purpose: the outbox does not need to
     * know what an envelope is to mail about one.
     *
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }

    /** @return BelongsTo<OutboundMail, $this> */
    public function resentFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_from_id');
    }

    public function mailContext(): MailContext
    {
        return MailContext::fromArray($this->context);
    }

    /**
     * A transport accepted the bytes and named the message. This is the strongest thing the
     * application can say on its own; it is not delivery.
     */
    public function markSentToProvider(string $mailer, ?string $messageId): void
    {
        $this->mailer = $mailer;
        $this->message_id = $messageId;
        $this->last_error = null;
        $this->moveTo(MailState::SentToProvider);
    }

    /**
     * An attempt raised a transport error and the queue will try again. The state stays
     * `queued`, because that is what is true: nothing has been handed over successfully.
     *
     * @param  string  $redactedError  Already through MailErrorRedactor.
     */
    public function recordAttemptFailure(string $mailer, string $redactedError): void
    {
        $this->mailer = $mailer;
        $this->last_error = $redactedError;
        $this->state_changed_at = Carbon::now();
        $this->save();
    }

    /**
     * @param  string  $redactedError  Already through MailErrorRedactor.
     */
    public function markFailed(string $redactedError): void
    {
        $this->last_error = $redactedError;
        $this->moveTo(MailState::Failed);
    }

    public function recordAttemptStarted(): void
    {
        $this->increment('attempts');
    }

    /**
     * Apply provider feedback. Returns whether the state actually moved, so a caller can
     * tell a genuine transition from a duplicate or out-of-order webhook without inspecting
     * the row twice.
     */
    public function applyFeedbackState(MailState $state, ?Carbon $at = null): bool
    {
        if (! $state->supersedes($this->state)) {
            return false;
        }

        $this->state = $state;
        $this->state_changed_at = $at ?? Carbon::now();
        $this->save();

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $payload  Already redacted by the caller.
     */
    public function recordEvent(
        MailEventSource $source,
        string $event,
        ?array $payload = null,
        ?Carbon $occurredAt = null,
    ): OutboundMailEvent {
        return $this->events()->create([
            'source' => $source,
            'event' => $event,
            'message_id' => $this->message_id,
            'payload' => $payload,
            'occurred_at' => $occurredAt ?? Carbon::now(),
        ]);
    }

    /**
     * @param  Builder<OutboundMail>  $query
     * @return Builder<OutboundMail>
     */
    public function scopeInState(Builder $query, MailState $state): Builder
    {
        return $query->where('state', $state->value);
    }

    /**
     * @param  Builder<OutboundMail>  $query
     * @return Builder<OutboundMail>
     */
    public function scopeForMessageId(Builder $query, string $messageId): Builder
    {
        return $query->where('message_id', $messageId);
    }

    protected static function newFactory(): Factory
    {
        return OutboundMailFactory::new();
    }

    private function moveTo(MailState $state): void
    {
        $this->state = $state;
        $this->state_changed_at = Carbon::now();
        $this->save();
    }
}
