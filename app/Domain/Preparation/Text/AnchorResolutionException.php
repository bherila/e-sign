<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use RuntimeException;

/**
 * Base type for every anchor failure, so callers can fail one document cleanly.
 *
 * The failure carries how many times the anchor matched, because the resolver counted them on
 * the way to deciding this was a failure. A caller that needs the number to explain itself —
 * "no match on page 3", "4 matches on page 1" — reads it here rather than scanning the runs a
 * second time, which on a document with many runs and many anchored fields is the same work
 * again for an answer already computed.
 */
abstract class AnchorResolutionException extends RuntimeException
{
    public function __construct(
        public readonly Anchor $anchor,
        string $message,
        public readonly int $matchCount = 0,
    ) {
        parent::__construct($message);
    }
}
