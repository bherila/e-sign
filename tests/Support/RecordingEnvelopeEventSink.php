<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;

/**
 * Captures what the state machine published, in order, without a database round trip.
 *
 * Used alongside — not instead of — the assertions against `esign_audit_events`: this proves
 * the sink was called with the right names and payloads, and the audit rows prove the call
 * happened inside the transition's transaction.
 */
final class RecordingEnvelopeEventSink implements EnvelopeEventSink
{
    /** @var list<array{envelope: string, event: EnvelopeEvent, payload: array<string, mixed>}> */
    public array $recorded = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        $this->recorded[] = [
            'envelope' => $envelope->public_id,
            'event' => $event,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['event']->value,
            $this->recorded,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payloadFor(EnvelopeEvent $event): ?array
    {
        foreach ($this->recorded as $entry) {
            if ($entry['event'] === $event) {
                return $entry['payload'];
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->recorded = [];
    }
}
