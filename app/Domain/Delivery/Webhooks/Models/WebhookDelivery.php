<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Models;

use App\Domain\Delivery\Webhooks\DeliveryState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to deliver one event to one endpoint.
 *
 * `public_id` is the per-attempt identity (`X-Firma-Delivery`); the event's
 * `public_id` is the logical identity that stays the same across every attempt
 * and every replay.
 *
 * @property int $id
 * @property string $public_id
 * @property int $outbox_event_id
 * @property int $webhook_endpoint_id
 * @property int $attempt
 * @property DeliveryState $state
 * @property CarbonImmutable|null $attempted_at
 * @property int|null $signature_timestamp
 * @property int|null $response_status
 * @property string|null $response_excerpt
 * @property string|null $error
 * @property CarbonImmutable|null $next_attempt_at
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'outbox_event_id',
        'webhook_endpoint_id',
        'attempt',
        'state',
        'attempted_at',
        'signature_timestamp',
        'response_status',
        'response_excerpt',
        'error',
        'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'state' => DeliveryState::class,
            'attempted_at' => 'immutable_datetime',
            'signature_timestamp' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            if (($delivery->public_id ?? '') === '') {
                $delivery->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<OutboxEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Attempts that are due but have not run: the backlog an operator cares
     * about. A retry scheduled for two hours from now is not a backlog.
     *
     * @param  Builder<WebhookDelivery>  $query
     * @return Builder<WebhookDelivery>
     */
    public function scopeOverdue(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query->where('state', DeliveryState::Pending->value)
            ->where('next_attempt_at', '<=', $now ?? CarbonImmutable::now());
    }
}
