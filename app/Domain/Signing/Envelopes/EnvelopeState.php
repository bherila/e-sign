<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

/**
 * Every position an envelope can hold.
 *
 * The native lifecycle from docs/HANDOFF.md section 6:
 *
 *     draft -> sent -> in_progress -> finalizing -> completed
 *                \-> cancelled | declined | expired
 *
 * `finalization_failed` is deliberately not terminal and deliberately not `completed`: a
 * finalization that did not produce a durable, validated, retrievable PDF stays visibly
 * failed and can be retried (AGENTS.md, "Fail closed"; docs/ARCHITECTURE.md invariant 5).
 *
 * The transition table lives in {@see EnvelopeStateMachine::TRANSITIONS} so there is exactly
 * one authority for what is legal, and docs/signing/state-machine.md renders it for humans.
 */
enum EnvelopeState: string
{
    /** Being prepared. Nobody has been invited; content is fully editable. */
    case Draft = 'draft';

    /** Invitations issued, no recipient has acted yet. */
    case Sent = 'sent';

    /** At least one recipient has submitted a value or accepted, but not all have signed. */
    case InProgress = 'in_progress';

    /** Every recipient has signed; rendering, sealing, validation, and publication are pending. */
    case Finalizing = 'finalizing';

    /** The final PDF exists, was validated, is durably stored, and is retrievable. */
    case Completed = 'completed';

    /** Withdrawn by the sender before completion. */
    case Cancelled = 'cancelled';

    /** A recipient refused. */
    case Declined = 'declined';

    /** The expiry computed at send passed before everyone signed. */
    case Expired = 'expired';

    /** Finalization was attempted and did not succeed. Retryable, never silently completed. */
    case FinalizationFailed = 'finalization_failed';

    /** No transition leaves these. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Declined, self::Expired => true,
            default => false,
        };
    }

    /** True while recipients may still act on the envelope. */
    public function isOpenForSigning(): bool
    {
        return $this === self::Sent || $this === self::InProgress;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
