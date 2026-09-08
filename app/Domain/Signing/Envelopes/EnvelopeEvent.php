<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

/**
 * The events the state machine publishes.
 *
 * Names come from `docs/compatibility/firma-capability-matrix.md` wherever the profile has
 * one, so the Firma-compatible facade forwards a name rather than inventing a mapping. Two
 * do not exist upstream and are namespaced `esign.` instead:
 *
 * - `esign.envelope.declined` — the matrix records (disagreement D12) that the profile has
 *   `signing_request.recipient.declined` but no envelope-level declined event, even though
 *   `status.declined` and `timestamps.declined_on` both exist. Emitting a fabricated
 *   `signing_request.declined` would put a name on the wire that no consumer subscribes to.
 * - `esign.envelope.finalization.failed` — the profile has no concept of a visibly failed
 *   finalization at all. It is ours, and it must stay distinguishable from `completed`.
 *
 * `signing_request.completed` is published later than upstream publishes it, on purpose:
 * only after the final PDF is generated, validated, durably stored, and retrievable
 * (AGENTS.md; the matrix marks the row "intentionally different"). The state machine
 * publishes it from {@see EnvelopeStateMachine::markCompleted()}, which the finalizer calls
 * once that is true — never from the last acceptance.
 */
enum EnvelopeEvent: string
{
    /** An envelope was created from a snapshot. */
    case Created = 'signing_request.created';

    /** Invitations were issued and the first stage was activated. */
    case Sent = 'signing_request.sent';

    /** One recipient attested. Does not imply the agreement is executed. */
    case RecipientSigned = 'signing_request.recipient.signed';

    /** One recipient refused. */
    case RecipientDeclined = 'signing_request.recipient.declined';

    /** The final artifact exists and is retrievable. Published from markCompleted(), not from signing. */
    case Completed = 'signing_request.completed';

    /** Withdrawn by the sender. */
    case Cancelled = 'signing_request.cancelled';

    /** Envelope-level decline. No upstream equivalent; see the class docblock. */
    case Declined = 'esign.envelope.declined';

    /** Expiry reached before execution. */
    case Expired = 'signing_request.expired';

    /** Finalization was attempted and failed. Retryable, never completion. */
    case FinalizationFailed = 'esign.envelope.finalization.failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
