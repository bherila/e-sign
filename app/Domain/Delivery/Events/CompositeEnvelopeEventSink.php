<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;

/**
 * Publishes one transition to several sinks, in order, in the caller's transaction.
 *
 * The audit trail and the webhook outbox are both sinks and neither replaces the other: the
 * audit store is the local history that survives an endpoint being disabled, and the outbox
 * is what a receiver subscribes to. Composing them keeps the state machine's port at one
 * argument instead of teaching it to hold a list.
 *
 * No sink is isolated from another. A throw from any of them aborts the transition, which is
 * the correct outcome for the failures that can actually happen here: every sink is a
 * database write against the same connection, so one failing means the transaction is
 * already doomed, and swallowing it would commit a transition whose event nobody recorded.
 */
final readonly class CompositeEnvelopeEventSink implements EnvelopeEventSink
{
    /** @var list<EnvelopeEventSink> */
    private array $sinks;

    public function __construct(EnvelopeEventSink ...$sinks)
    {
        $this->sinks = array_values($sinks);
    }

    /**
     * The sinks this composite publishes to, in order.
     *
     * Exposed so a test can assert what a deployment is actually wired to publish, which is
     * a property worth pinning: a refactor that dropped the audit sink would leave every
     * existing test green and every install without a local event history.
     *
     * @return list<EnvelopeEventSink>
     */
    public function sinks(): array
    {
        return $this->sinks;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        foreach ($this->sinks as $sink) {
            $sink->record($envelope, $event, $payload);
        }
    }
}
