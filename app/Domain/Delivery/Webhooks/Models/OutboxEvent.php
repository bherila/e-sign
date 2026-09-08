<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Models;

use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One logical event in the transactional outbox.
 *
 * Immutable by construction and by enforcement: `public_id` is the event
 * identity a receiver deduplicates on, and `canonical_body` is the exact JSON
 * that every attempt signs and sends. Editing either after the fact would
 * silently invalidate signatures already delivered, so the model refuses
 * updates and deletes the way the audit trail does.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $event_name
 * @property array<string, mixed> $payload
 * @property string $canonical_body
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 */
class OutboxEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'event_name',
        'payload',
        'canonical_body',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (($event->public_id ?? '') === '') {
                $event->public_id = (string) Str::ulid();
            }
        });

        static::updating(static function (self $event): never {
            throw new RuntimeException('outbox_events is immutable; a recorded event cannot be updated.');
        });

        static::deleting(static function (self $event): never {
            throw new RuntimeException('outbox_events is immutable; a recorded event cannot be deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
