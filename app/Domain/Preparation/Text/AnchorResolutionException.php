<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use RuntimeException;

/** Base type for every anchor failure, so callers can fail one document cleanly. */
abstract class AnchorResolutionException extends RuntimeException
{
    public function __construct(public readonly Anchor $anchor, string $message)
    {
        parent::__construct($message);
    }
}
