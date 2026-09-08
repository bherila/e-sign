<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/** A required anchor string does not occur anywhere in scope. */
final class AnchorNotFoundException extends AnchorResolutionException
{
    public static function for(Anchor $anchor): self
    {
        return new self($anchor, sprintf(
            'Required %s was not found. The document must contain this exact string; '
            .'extraction is case-sensitive and does not normalise whitespace, ligatures or hyphenation.',
            $anchor->describe(),
        ));
    }
}
