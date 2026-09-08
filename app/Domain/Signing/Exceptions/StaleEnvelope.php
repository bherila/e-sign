<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * The compare-and-swap lost: the row moved between the caller reading it and the caller
 * writing it.
 *
 * This is how docs/ARCHITECTURE.md invariant 6 is satisfied. Two callers racing to cancel,
 * expire, sign, or finalize the same envelope both find a legal transition; exactly one
 * `UPDATE ... WHERE version = ?` affects a row, and the other one gets this. The loser is
 * never left believing it succeeded, and the row never ends up in a state neither caller
 * asked for.
 *
 * A retry is often correct — re-read the envelope and decide again against what is now
 * true. Retrying blindly is not: the winning transition may have made the caller's intent
 * illegal.
 */
final class StaleEnvelope extends SigningException
{
    private function __construct(
        string $message,
        public readonly string $subject,
        public readonly int $expectedVersion,
    ) {
        parent::__construct($message);
    }

    public static function envelope(int $expectedVersion): self
    {
        return new self(
            'The envelope changed since it was read (expected version '.$expectedVersion.'); re-read it and try again.',
            'envelope',
            $expectedVersion,
        );
    }

    public static function recipient(int $expectedVersion): self
    {
        return new self(
            'The recipient changed since it was read (expected version '.$expectedVersion.'); re-read it and try again.',
            'recipient',
            $expectedVersion,
        );
    }

    public function code(): string
    {
        return 'stale_'.$this->subject;
    }
}
