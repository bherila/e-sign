<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Models;

use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A workspace's webhook destination.
 *
 * Secrets are `encrypted` casts: ciphertext at rest, and never rendered by a
 * console command, an audit payload, a log line, or an exception message. The
 * plaintext of a new secret is shown exactly once, at the moment it is created
 * or rotated, because there is no way to recover it afterwards.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $url
 * @property string|null $description
 * @property list<string>|null $event_filter
 * @property string $secret_current
 * @property string|null $secret_previous
 * @property CarbonImmutable|null $secret_previous_expires_at
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $disabled_reason
 * @property int $consecutive_failures
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'url',
        'description',
        'event_filter',
        'secret_current',
        'secret_previous',
        'secret_previous_expires_at',
        'disabled_at',
        'disabled_reason',
        'consecutive_failures',
    ];

    /**
     * Secrets are hidden so a careless `toArray()` in a controller, a log
     * context, or a queue payload cannot carry them out of the process.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret_current',
        'secret_previous',
    ];

    protected function casts(): array
    {
        return [
            'event_filter' => 'array',
            'secret_current' => 'encrypted',
            'secret_previous' => 'encrypted',
            'secret_previous_expires_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $endpoint): void {
            if (($endpoint->public_id ?? '') === '') {
                $endpoint->public_id = (string) Str::ulid();
            }
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

    /**
     * @param  Builder<WebhookEndpoint>  $query
     * @return Builder<WebhookEndpoint>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    public function isEnabled(): bool
    {
        return $this->disabled_at === null;
    }

    /**
     * A null filter means every event. A list matches exactly: no wildcards, so
     * an event family added later is never delivered to an endpoint that did
     * not ask for it by name.
     */
    public function wants(string $eventName): bool
    {
        $filter = $this->event_filter;

        return $filter === null || $filter === [] || in_array($eventName, $filter, true);
    }

    /**
     * Every secret an attempt signs with, current first.
     *
     * During the rotation overlap this is two secrets, and the request carries
     * a signature under each; after `secret_previous_expires_at` it is one
     * again, and a receiver still holding the old secret stops verifying —
     * which is the point of the deadline.
     *
     * @return list<string>
     */
    public function signingSecrets(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $secrets = [$this->secret_current];

        $previous = $this->secret_previous;
        $expiresAt = $this->secret_previous_expires_at;

        if (is_string($previous) && $previous !== '' && $expiresAt !== null && $expiresAt->greaterThan($now)) {
            $secrets[] = $previous;
        }

        return $secrets;
    }

    protected static function newFactory(): Factory
    {
        return WebhookEndpointFactory::new();
    }
}
