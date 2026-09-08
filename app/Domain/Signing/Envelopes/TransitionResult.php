<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Models\Envelope;

/**
 * What one transition did.
 *
 * Returned rather than "the envelope, go and look at it" so a caller can report the move it
 * caused without diffing model state, and so a transition that legitimately changes nothing
 * is distinguishable from one that did.
 */
final readonly class TransitionResult
{
    /**
     * @param  list<EnvelopeEvent>  $events  Published to the sink inside the same transaction.
     */
    public function __construct(
        public Envelope $envelope,
        public EnvelopeState $from,
        public EnvelopeState $to,
        public array $events = [],
    ) {}

    public function changedState(): bool
    {
        return $this->from !== $this->to;
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn (EnvelopeEvent $event): string => $event->value, $this->events);
    }
}
