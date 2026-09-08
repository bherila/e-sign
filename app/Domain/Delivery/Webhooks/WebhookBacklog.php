<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;

/**
 * Reads the outbox's operational state.
 *
 * "Backlog" means attempts that are due and have not run. A retry scheduled for
 * twelve hours from now is not a backlog — counting it as one would make a
 * healthy instance with one broken receiver look like a stalled queue, which is
 * how alerting gets ignored.
 */
final class WebhookBacklog
{
    public function snapshot(?CarbonImmutable $now = null): BacklogSnapshot
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDay();

        $oldestOverdue = WebhookDelivery::query()->overdue($now)->min('next_attempt_at');

        return new BacklogSnapshot(
            overdue: WebhookDelivery::query()->overdue($now)->count(),
            oldestOverdueSeconds: $oldestOverdue === null
                ? null
                : max(0, $now->getTimestamp() - CarbonImmutable::parse($oldestOverdue)->getTimestamp()),
            scheduled: WebhookDelivery::query()
                ->where('state', DeliveryState::Pending->value)
                ->where('next_attempt_at', '>', $now)
                ->count(),
            succeededLast24h: $this->countSince(DeliveryState::Succeeded, $since),
            exhaustedLast24h: $this->countSince(DeliveryState::Exhausted, $since),
            failedLast24h: $this->countSince(DeliveryState::Failed, $since),
            disabledEndpoints: WebhookEndpoint::query()->whereNotNull('disabled_at')->count(),
        );
    }

    private function countSince(DeliveryState $state, CarbonImmutable $since): int
    {
        return WebhookDelivery::query()
            ->where('state', $state->value)
            ->where('attempted_at', '>=', $since)
            ->count();
    }
}
