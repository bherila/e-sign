<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Text\AnchorOccurrence;

/**
 * What an anchor is allowed to do to the field's rectangle.
 *
 * A field carries exactly one rectangle, and schema 1.0 requires it: the rectangle is what the
 * editor draws, what assembly stamps, and what a signer touches. So a field that also carries an
 * anchor has *two* statements about where it goes, and this enum is the document saying which of
 * them wins. It is required whenever an `anchor` is present and has no default, for the same
 * reason {@see AnchorOccurrence} refuses a "first match wins"
 * fallback: an unstated precedence is a guess, and a guess moves a signature box.
 *
 * - **`replace`** — the anchor is authoritative for the field's *position*. The rectangle's
 *   `width` and `height` are the field's size, which an anchor never supplies, and its `x` and
 *   `y` are a declared placeholder that resolution overwrites. Nothing is ever placed at the
 *   placeholder: if the anchor cannot be resolved the send is refused.
 * - **`cross_check`** — the rectangle is authoritative and the anchor is a check on it. The
 *   document is asserting "this box sits at these coordinates, and the text `X` is there";
 *   resolution must land within `anchor.tolerance` points of the declared corner, and a larger
 *   disagreement is an error rather than a silent move either way. This is the mode for a
 *   document whose layout the consumer generates itself and whose coordinates are therefore
 *   already known — see docs/preparation/anchors.md.
 */
enum AnchorPlacementMode: string
{
    /** The anchor supplies the position; the rectangle supplies only the size. */
    case Replace = 'replace';

    /** The rectangle supplies the position; the anchor must agree with it within a tolerance. */
    case CrossCheck = 'cross_check';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }

    /** True when resolution writes the resolved rectangle into the field. */
    public function replacesRect(): bool
    {
        return $this === self::Replace;
    }
}
