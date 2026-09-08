<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/** An anchor asked for occurrence N but fewer than N matches exist. */
final class OccurrenceOutOfRangeException extends AnchorResolutionException
{
    public static function for(Anchor $anchor, int $matches): self
    {
        return new self($anchor, sprintf(
            '%s requested occurrence %s but only %d %s found.',
            ucfirst($anchor->describe()),
            $anchor->occurrence->describe(),
            $matches,
            $matches === 1 ? 'match was' : 'matches were',
        ));
    }
}
