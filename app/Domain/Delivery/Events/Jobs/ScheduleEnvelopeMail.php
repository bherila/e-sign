<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events\Jobs;

use App\Domain\Delivery\Events\EnvelopeMailScheduler;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Turns one committed envelope event into the messages it implies.
 *
 * Dispatched by `App\Domain\Delivery\Events\DeliveryEnvelopeEventSink` with `->afterCommit()`,
 * which is the point of it existing at all: the sink runs inside the transaction that made
 * the transition, and mail must not.
 *
 * Carries the envelope's public ULID and the event name rather than the model. A serialized
 * model is a snapshot of a row that may have moved on — cancelled, declined, expired — and
 * the scheduler's guards depend on reading the envelope as it is now.
 */
class ScheduleEnvelopeMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * `afterCommit` is set at dispatch rather than as a property here: `Queueable` already
     * declares that property with a different default and PHP rejects a mismatched
     * redeclaration (the same reason `SendOutboundMail` gives).
     */
    public function __construct(
        public readonly string $envelopePublicId,
        public readonly string $eventName,
    ) {}

    public function handle(EnvelopeMailScheduler $scheduler): void
    {
        $envelope = Envelope::query()
            ->with(['workspace', 'creator'])
            ->where('public_id', $this->envelopePublicId)
            ->first();

        if ($envelope === null) {
            // The envelope is gone. Nothing to say and nobody who would recognise it.
            return;
        }

        $event = EnvelopeEvent::tryFrom($this->eventName);

        if ($event === null) {
            // A job left on the queue across a deploy that removed an event name. Failing
            // would retry it six times and then alert on a message that can never be built.
            return;
        }

        $scheduler->schedule($envelope, $event);
    }
}
