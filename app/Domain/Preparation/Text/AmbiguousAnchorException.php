<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/** An anchor asked for one occurrence but the string matches more than once. */
final class AmbiguousAnchorException extends AnchorResolutionException
{
    public static function for(Anchor $anchor, int $matches): self
    {
        return new self($anchor, sprintf(
            '%s matched %d times but was declared as the sole occurrence. Declare an occurrence '
            .'index (1..%d), restrict the anchor to a single page, or use an explicit rectangle. '
            .'The first match is never assumed.',
            ucfirst($anchor->describe()),
            $matches,
            $matches,
        ));
    }
}
