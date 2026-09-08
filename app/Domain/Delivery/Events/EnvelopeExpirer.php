<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\SigningException;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;

/**
 * Applies expiries that have arrived.
 *
 * The clock does not move a row on its own, so something has to ask. This is that something,
 * and it is deliberately thin: it finds envelopes whose `expires_at` has passed and calls
 * `EnvelopeStateMachine::expire()`, which re-checks the deadline under a lock and refuses if
 * it is not due. The rule about what expiry means stays in the one state machine
 * (AGENTS.md), and this class cannot expire anything the machine would not.
 *
 * A refusal is skipped rather than fatal. Between the scan and the transition an envelope can
 * be cancelled, declined, or finished; that race resolving the other way is a legal outcome,
 * not an error, and one of them must not stop the rest of the batch.
 */
final readonly class EnvelopeExpirer
{
    public function __construct(private EnvelopeStateMachine $machine) {}

    /**
     * @return list<Envelope> The envelopes that were expired.
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $expired = [];

        $due = Envelope::query()
            ->whereIn('state', [EnvelopeState::Sent->value, EnvelopeState::InProgress->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($due as $envelope) {
            try {
                $this->machine->expire($envelope);
                $expired[] = $envelope;
            } catch (SigningException) {
                continue;
            }
        }

        return $expired;
    }
}
