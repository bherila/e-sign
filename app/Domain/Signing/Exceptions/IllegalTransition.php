<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use Carbon\CarbonImmutable;

/**
 * The requested transition does not exist for the state the envelope or recipient is in.
 *
 * Distinct from {@see StaleEnvelope}: this one means the move is illegal from here no matter
 * who asks and no matter when, while a stale failure means the move was legal but somebody
 * else moved first. Cancelling a completed envelope is illegal; cancelling an envelope that
 * completed a millisecond ago is stale.
 */
final class IllegalTransition extends SigningException
{
    private function __construct(
        string $message,
        public readonly string $transition,
        public readonly string $from,
    ) {
        parent::__construct($message);
    }

    public static function envelope(string $transition, EnvelopeState $from): self
    {
        return new self(
            sprintf('Cannot %s an envelope in state "%s".', $transition, $from->value),
            $transition,
            $from->value,
        );
    }

    public static function recipient(string $transition, RecipientState $from): self
    {
        return new self(
            sprintf('Cannot %s for a recipient in state "%s".', $transition, $from->value),
            $transition,
            $from->value,
        );
    }

    /**
     * Expiry is a fact about the clock, not a button.
     *
     * `expire()` exists for the scheduler to apply an expiry that has actually arrived. An
     * envelope with no expiry, or one whose expiry is still in the future, is withdrawn with
     * `cancel()`, which records a reason and says honestly what happened.
     */
    public static function expiryNotDue(?CarbonImmutable $expiresAt): self
    {
        return new self(
            $expiresAt === null
                ? 'This envelope has no expiry, so it cannot expire; cancel it instead.'
                : 'This envelope does not expire until '.$expiresAt->toIso8601String().'.',
            'expire',
            'not_due',
        );
    }

    /**
     * The clock says this envelope is over, whatever the state column still says.
     *
     * `ExpireCommand` runs hourly, so `expires_at` passing and `state` becoming `expired`
     * are up to an hour apart — unbounded if the scheduler is broken. Reading the state
     * alone let a recipient sign inside that gap, which is a signature on an agreement whose
     * sender was told it had closed (docs/security/review-2026-09.md finding S-4). The sweep
     * still owns the transition and the event; this only refuses to add to an envelope the
     * sweep is going to close.
     */
    public static function envelopeExpired(CarbonImmutable $expiresAt): self
    {
        return new self(
            'This envelope expired at '.$expiresAt->toIso8601String().'.',
            'accept',
            'expired',
        );
    }

    public function code(): string
    {
        return 'illegal_transition';
    }
}
