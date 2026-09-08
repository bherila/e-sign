<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use App\Domain\Delivery\Webhooks\Exceptions\OutboxWriteOutsideTransactionException;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only supported way to publish a domain event.
 *
 * **Transactional-outbox rule.** `record()` must be called inside the same
 * database transaction as the domain transition it describes. The event row and
 * the transition then commit together or roll back together, which is the whole
 * point of the pattern (docs/HANDOFF.md §11):
 *
 *     DB::transaction(function () use ($envelope, $outbox) {
 *         $envelope->markCompleted();
 *         $outbox->record($envelope->workspace, 'signing_request.completed', [...]);
 *     });
 *
 * A call with no transaction open is refused, not warned about. Fan-out is a
 * queued job dispatched *after* the commit, so a worker can never read an event
 * row that a rollback is about to remove.
 *
 * The completion event carries one more rule that lives with its caller, not
 * here: `signing_request.completed` may only be recorded once the validated
 * final PDF is durably stored and retrievable (AGENTS.md, "Fail closed").
 */
final class OutboxWriter
{
    public function __construct(private readonly ?string $queue = null) {}

    /**
     * @param  array<string, mixed>  $payload  The envelope's `data` object.
     *
     * @throws Exceptions\UnknownEventNameException
     * @throws OutboxWriteOutsideTransactionException
     */
    public function record(
        Workspace $workspace,
        string $eventName,
        array $payload,
        ?CarbonImmutable $occurredAt = null,
    ): OutboxEvent {
        WebhookEventName::assertKnown($eventName);
        $this->assertInsideTransaction($eventName);

        $eventId = (string) Str::ulid();
        $occurredAt ??= CarbonImmutable::now();

        $event = new OutboxEvent([
            'workspace_id' => $workspace->getKey(),
            'event_name' => $eventName,
            'payload' => $payload,
            'canonical_body' => EventEnvelope::encode(
                eventId: $eventId,
                eventName: $eventName,
                workspacePublicId: $workspace->public_id,
                payload: $payload,
                occurredAt: $occurredAt,
            ),
            'occurred_at' => $occurredAt,
        ]);
        $event->public_id = $eventId;
        $event->save();

        DispatchOutboxEvent::dispatch($event->getKey())
            ->onQueue($this->queue ?? (string) config('esign.delivery.webhooks.queue', 'default'))
            ->afterCommit();

        return $event;
    }

    private function assertInsideTransaction(string $eventName): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        throw new OutboxWriteOutsideTransactionException(
            'Refusing to record the outbox event "'.$eventName.'" with no transaction open. '.
            'An event must commit atomically with the domain transition it describes; wrap the '.
            'caller in DB::transaction().'
        );
    }
}
