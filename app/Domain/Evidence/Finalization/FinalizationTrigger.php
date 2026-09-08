<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;

/**
 * What starts finalization: the transition that made it due.
 *
 * `finalizing` is not a state anything polls. It is reached by exactly one move — the last
 * outstanding recipient accepting — and until this sink existed nothing in `app/` turned that
 * move into work, so an envelope arrived at `finalizing` and stayed there (issue
 * [#94](https://github.com/bherila/e-sign/issues/94)). A sink is the right place for it
 * because the state machine already publishes every transition through one, and a trigger
 * bolted onto a controller would be a signing rule living outside the state machine, which
 * `AGENTS.md` forbids.
 *
 * ## Why the dispatch is after commit
 *
 * {@see EnvelopeEventSink} is called **inside** the transaction that made the change and
 * requires an implementation to be a database write and nothing else. This one is not a
 * database write, so it obeys the other half of the rule that
 * {@see DeliveryEnvelopeEventSink} follows for mail: the job is
 * dispatched with `->afterCommit()`, so a worker cannot pick it up before the transition is
 * durable and never picks it up at all if the acceptance rolls back. A finalization started
 * from an uncommitted acceptance would find an envelope that is not `finalizing` and fail for
 * a reason that never happened.
 *
 * ## Why the state is the test, not the event name
 *
 * `signing_request.recipient.signed` is published for *every* acceptance. Only the one that
 * left the envelope in `finalizing` means everybody has signed, and the state machine has
 * already committed that column by the time it calls the sink — so the pair
 * (event, resulting state) is the exact condition, and reading it needs no second query and
 * no recount of outstanding recipients.
 *
 * `retryFinalization()` deliberately does not reach this class: it publishes no event, and a
 * failed finalization is an explicit operator decision rather than something a transition
 * restarts on its own (see {@see FinalizeEnvelope}). The safety net for a *lost* job is
 * `esign:finalization:resume`, not this trigger.
 */
final class FinalizationTrigger implements EnvelopeEventSink
{
    /**
     * @param  array<string, mixed>  $payload  Unused: the decision is the transition itself,
     *                                         and the job carries only the envelope's public id.
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        if ($event !== EnvelopeEvent::RecipientSigned || $envelope->state !== EnvelopeState::Finalizing) {
            return;
        }

        FinalizeEnvelope::dispatch($envelope->public_id)->afterCommit();
    }
}
