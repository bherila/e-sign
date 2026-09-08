<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\WebhookSecret;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'workspace_id' => Workspace::factory(),
            // RFC 5737 TEST-NET-3 over HTTPS: public as far as the destination
            // policy is concerned, and unroutable in reality.
            'url' => 'https://203.0.113.10/hooks/'.Str::lower(Str::random(8)),
            'description' => 'Synthetic endpoint',
            'event_filter' => null,
            'secret_current' => WebhookSecret::generate(),
            'secret_previous' => null,
            'secret_previous_expires_at' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
            'consecutive_failures' => 0,
        ];
    }

    public function disabled(string $reason = 'Disabled by an operator.'): static
    {
        return $this->state(fn (): array => [
            'disabled_at' => CarbonImmutable::now(),
            'disabled_reason' => $reason,
        ]);
    }

    /**
     * @param  list<string>  $events
     */
    public function filteredTo(array $events): static
    {
        return $this->state(fn (): array => ['event_filter' => $events]);
    }

    public function midRotation(string $previousSecret, ?CarbonImmutable $expiresAt = null): static
    {
        return $this->state(fn (): array => [
            'secret_previous' => $previousSecret,
            'secret_previous_expires_at' => $expiresAt ?? CarbonImmutable::now()->addHours(168),
        ]);
    }
}
