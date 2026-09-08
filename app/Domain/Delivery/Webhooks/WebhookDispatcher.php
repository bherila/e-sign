<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use App\Domain\Delivery\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use Carbon\CarbonImmutable;

/**
 * Turns a recorded event into attempts, and keeps an endpoint's health up to
 * date as those attempts settle.
 *
 * Fan-out is deliberately narrow: an endpoint receives an event only if it is
 * enabled and its filter names the event. A disabled endpoint is not queued
 * and silently dropped later — no row is created for it at all, so the delivery
 * history does not fill with attempts that were never going to be made.
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly RetrySchedule $schedule,
        private readonly AuditRecorder $audit,
        private readonly int $autoDisableAfter,
        private readonly ?string $queue = null,
    ) {}

    /**
     * Create the first attempt for every endpoint that wants this event.
     *
     * @return list<WebhookDelivery>
     */
    public function fanOut(OutboxEvent $event): array
    {
        $endpoints = WebhookEndpoint::query()
            ->enabled()
            ->where('workspace_id', $event->workspace_id)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($event->event_name));

        $deliveries = [];

        foreach ($endpoints as $endpoint) {
            $deliveries[] = $this->queueAttempt($event, $endpoint, attempt: 1, dueAt: CarbonImmutable::now());
        }

        return $deliveries;
    }

    /**
     * Re-deliver an already recorded event.
     *
     * A replay is a new attempt, never a new event: the row gets the next
     * attempt number, its own attempt identity, and a fresh signature
     * timestamp, while the event identity a receiver deduplicates on is
     * unchanged. That is what makes replay safe to hand an administrator — an
     * idempotent receiver will recognise the event it already processed.
     *
     * @return list<WebhookDelivery>
     */
    public function replay(OutboxEvent $event, ?WebhookEndpoint $only = null): array
    {
        $endpoints = $only !== null
            ? collect([$only])
            : WebhookEndpoint::query()
                ->enabled()
                ->where('workspace_id', $event->workspace_id)
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($event->event_name));

        $deliveries = [];

        foreach ($endpoints as $endpoint) {
            if (! $endpoint->isEnabled()) {
                continue;
            }

            $deliveries[] = $this->queueAttempt(
                $event,
                $endpoint,
                attempt: $this->nextAttemptNumber($event, $endpoint),
                dueAt: CarbonImmutable::now(),
            );
        }

        return $deliveries;
    }

    /**
     * When the attempt after this one is due, or null when the schedule is out.
     */
    public function nextAttemptAt(int $failedAttempt): ?CarbonImmutable
    {
        $delay = $this->schedule->delayAfterAttempt($failedAttempt);

        return $delay === null ? null : CarbonImmutable::now()->addSeconds($delay);
    }

    public function queueRetry(WebhookDelivery $failed, CarbonImmutable $dueAt): WebhookDelivery
    {
        return $this->queueAttempt(
            $failed->event,
            $failed->endpoint,
            attempt: $failed->attempt + 1,
            dueAt: $dueAt,
        );
    }

    /**
     * A delivered event clears the endpoint's failure streak.
     */
    public function registerSuccess(WebhookEndpoint $endpoint): void
    {
        if ($endpoint->consecutive_failures === 0) {
            return;
        }

        $endpoint->consecutive_failures = 0;
        $endpoint->save();
    }

    /**
     * An event we have given up on advances the streak, and enough of them
     * disable the endpoint with a reason an operator can read.
     */
    public function registerFailure(WebhookEndpoint $endpoint): void
    {
        $endpoint->consecutive_failures++;

        if ($endpoint->isEnabled() && $endpoint->consecutive_failures >= $this->autoDisableAfter) {
            $endpoint->disabled_at = CarbonImmutable::now();
            $endpoint->disabled_reason = 'Auto-disabled after '.$endpoint->consecutive_failures.
                ' consecutive undeliverable events. Fix the receiver, then re-enable and replay.';

            $endpoint->save();

            $this->audit->record(
                AuditActor::system('webhook-delivery'),
                'webhook.endpoint.auto_disabled',
                $endpoint,
                [
                    'endpoint' => $endpoint->public_id,
                    'consecutive_failures' => $endpoint->consecutive_failures,
                ],
            );

            return;
        }

        $endpoint->save();
    }

    private function queueAttempt(
        OutboxEvent $event,
        WebhookEndpoint $endpoint,
        int $attempt,
        CarbonImmutable $dueAt,
    ): WebhookDelivery {
        $delivery = WebhookDelivery::create([
            'outbox_event_id' => $event->getKey(),
            'webhook_endpoint_id' => $endpoint->getKey(),
            'attempt' => $attempt,
            'state' => DeliveryState::Pending,
            'next_attempt_at' => $dueAt,
        ]);

        $job = DeliverWebhook::dispatch($delivery->getKey())
            ->onQueue($this->queue ?? (string) config('esign.delivery.webhooks.queue', 'default'));

        if ($dueAt->isFuture()) {
            $job->delay($dueAt);
        }

        return $delivery;
    }

    private function nextAttemptNumber(OutboxEvent $event, WebhookEndpoint $endpoint): int
    {
        $highest = WebhookDelivery::query()
            ->where('outbox_event_id', $event->getKey())
            ->where('webhook_endpoint_id', $endpoint->getKey())
            ->max('attempt');

        return ((int) $highest) + 1;
    }
}
