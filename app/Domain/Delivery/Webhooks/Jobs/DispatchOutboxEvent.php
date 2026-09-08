<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Jobs;

use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans one recorded event out to the endpoints that want it.
 *
 * Dispatched `afterCommit()` by the outbox writer, so this job cannot observe
 * an event row whose transaction is about to roll back. It carries the row's
 * key rather than the model: the payload is immutable and re-read here, and a
 * serialized model in a queue payload is a copy that can go stale.
 */
final class DispatchOutboxEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $outboxEventId) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $event = OutboxEvent::query()->find($this->outboxEventId);

        if ($event === null) {
            return;
        }

        $dispatcher->fanOut($event);
    }
}
