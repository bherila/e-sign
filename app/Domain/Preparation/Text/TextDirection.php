<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/**
 * The direction a text run advances in, expressed in native (displayed) space.
 *
 * A run that is horizontal in PDF user space becomes vertical in native space on a
 * page with /Rotate 90 or 270, so the direction has to be measured after the display
 * rotation, not before it. Anything that is not one of the four axis directions is
 * `Other`: such a run still has a bounding box, but it cannot be sliced per character.
 */
enum TextDirection: string
{
    case LeftToRight = 'left_to_right';
    case RightToLeft = 'right_to_left';
    case TopToBottom = 'top_to_bottom';
    case BottomToTop = 'bottom_to_top';
    case Other = 'other';

    /** True when characters advance along the native x axis. */
    public function isHorizontal(): bool
    {
        return $this === self::LeftToRight || $this === self::RightToLeft;
    }

    /** True when characters advance along the native y axis. */
    public function isVertical(): bool
    {
        return $this === self::TopToBottom || $this === self::BottomToTop;
    }

    /** True when the run advances towards decreasing coordinates. */
    public function isReversed(): bool
    {
        return $this === self::RightToLeft || $this === self::BottomToTop;
    }

    /**
     * Classify an advance vector measured in native units.
     */
    public static function fromAdvance(float $dx, float $dy, float $tolerance = 1.0e-6): self
    {
        $horizontal = abs($dy) <= $tolerance;
        $vertical = abs($dx) <= $tolerance;

        return match (true) {
            $horizontal && $dx > $tolerance => self::LeftToRight,
            $horizontal && $dx < -$tolerance => self::RightToLeft,
            $vertical && $dy > $tolerance => self::TopToBottom,
            $vertical && $dy < -$tolerance => self::BottomToTop,
            // A zero-length advance is degenerate but not rotated; treat it as normal.
            $horizontal && $vertical => self::LeftToRight,
            default => self::Other,
        };
    }
}
