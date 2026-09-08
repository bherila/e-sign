<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * Assent was offered against content that is no longer what the envelope holds.
 *
 * docs/ARCHITECTURE.md invariant 2: an acceptance binds to a specific review revision,
 * material values, consent version, and recipient session, and a stale submission fails and
 * requires re-review. The recipient states what they read — the material digest and the
 * envelope version — and if either has moved, the acceptance is refused rather than recorded
 * against text nobody showed them.
 *
 * The remedy is always the same and is never automatic: show the current document again and
 * take a fresh acceptance.
 */
final class StaleReview extends SigningException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $reviewed,
        public readonly string $current,
    ) {
        parent::__construct($message);
    }

    public static function materialValuesMoved(string $reviewed, string $current): self
    {
        return new self(
            'The material values changed since this recipient reviewed the envelope; a fresh review is required.',
            'material_values_moved',
            $reviewed,
            $current,
        );
    }

    public static function envelopeVersionMoved(int $reviewed, int $current): self
    {
        return new self(
            'The envelope changed since this recipient reviewed it; a fresh review is required.',
            'envelope_version_moved',
            (string) $reviewed,
            (string) $current,
        );
    }

    public function code(): string
    {
        return 'stale_review';
    }
}
