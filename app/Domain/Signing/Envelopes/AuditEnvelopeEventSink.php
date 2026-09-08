<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Models\Envelope;

/**
 * The default sink: one row in the append-only `esign_audit_events` store per transition.
 *
 * A database write and nothing else, which is what {@see EnvelopeEventSink} requires of an
 * implementation — so the event commits and rolls back with the transition it describes.
 *
 * The Evidence module owns the envelope audit trail (docs/ARCHITECTURE.md) and writes into
 * the same table; this is the Signing module's writer into it. The webhook outbox (issue
 * #29) is a second sink of the same shape, not a replacement for this one: an event that is
 * only ever a webhook delivery leaves no local history when the endpoint is disabled.
 */
final readonly class AuditEnvelopeEventSink implements EnvelopeEventSink
{
    public function __construct(private AuditRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        $this->recorder->record(
            AuditActor::system('signing.envelope_state_machine'),
            $event->value,
            $envelope,
            ['envelope' => $envelope->public_id] + $payload,
        );
    }
}
