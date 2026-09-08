<?php

declare(strict_types=1);

namespace App\Domain\Signing\Contracts;

use App\Domain\Signing\Envelopes\AuditEnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;

/**
 * Where the state machine publishes what happened.
 *
 * The sink is called **inside the transaction that made the change**. That is the whole
 * point of the port: a state change and its event are one atomic fact, so a rolled-back
 * transition cannot leave an event behind and a delivered event cannot describe a
 * transition that did not commit (docs/HANDOFF.md section 11).
 *
 * An implementation must therefore be a database write and nothing else. Do not send mail,
 * call an endpoint, or dispatch to a non-database queue from here: those are at-least-once
 * transports, and running them inside the lock either blocks the transition or delivers an
 * event for a transaction that later rolls back. The webhook outbox (issue #29) is exactly
 * this shape — record a row now, deliver it afterwards.
 *
 * The default binding is {@see AuditEnvelopeEventSink}, which
 * writes to the append-only `esign_audit_events` store. {@see NullEnvelopeEventSink} exists
 * for callers that want no trail at all, which in practice means a narrow unit test.
 */
interface EnvelopeEventSink
{
    /**
     * @param  array<string, mixed>  $payload  Facts about the transition, already minimized.
     *                                         Never raw request data and never a secret.
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void;
}
