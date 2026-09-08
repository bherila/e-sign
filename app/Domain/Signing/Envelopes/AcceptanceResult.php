<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Models\RecipientAttestation;

/**
 * One acceptance, and what it did to the envelope.
 *
 * `replayed` is true when the request repeated an acceptance that already existed for the
 * same session and the same material digest. The attestation returned is then the original
 * one, unchanged, and no event was published — because nothing happened, which is precisely
 * what invariant 7 promises a retry will do.
 */
final readonly class AcceptanceResult
{
    /**
     * @param  list<EnvelopeEvent>  $events
     */
    public function __construct(
        public Envelope $envelope,
        public EnvelopeRecipient $recipient,
        public RecipientAttestation $attestation,
        public bool $replayed,
        public EnvelopeState $from,
        public EnvelopeState $to,
        public array $events = [],
    ) {}

    /** True when this acceptance was the last one outstanding and finalization may begin. */
    public function completedSigning(): bool
    {
        return $this->to === EnvelopeState::Finalizing;
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn (EnvelopeEvent $event): string => $event->value, $this->events);
    }
}
