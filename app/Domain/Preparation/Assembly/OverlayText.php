<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\TcPdf\CoreFontMetrics;
use InvalidArgumentException;

/**
 * A line of text drawn inside a native rectangle.
 *
 * One line, deliberately. Wrapping is a decision about the value being drawn — how much of a
 * field's rectangle it may use, whether it should shrink instead, whether a break mid-word is
 * acceptable — and the caller that owns the value is the only one that can make it. The
 * assembler is left with a mechanical job: put these glyphs at this size at this point.
 * {@see CoreFontMetrics::wrap()} is the shared helper for callers that want the ordinary
 * answer.
 *
 * The baseline sits `baselineOffset` points below the rectangle's top edge, in native space,
 * so a caller that has measured its own line box places it exactly rather than describing it
 * and hoping. When it is null the assembler centres a cap-height line vertically in the
 * rectangle, which is what a single-line field value wants.
 */
final readonly class OverlayText implements PageOverlay
{
    /**
     * @param  int  $page  1-based page number.
     * @param  NativeRect  $rect  The box the text belongs to, in native coordinates.
     * @param  string  $text  The line to draw. Never truncated by the assembler.
     * @param  float  $fontSize  Size in points.
     * @param  float|null  $baselineOffset  Points below the rect's top edge, or null to centre.
     * @param  array{float, float, float}  $fillRgb  Components in 0..1.
     */
    public function __construct(
        public int $page,
        public NativeRect $rect,
        public string $text,
        public float $fontSize = 10.0,
        public ?float $baselineOffset = null,
        public array $fillRgb = [0.0, 0.0, 0.0],
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Overlay page numbers are 1-based.');
        }

        if ($fontSize <= 0.0 || ! is_finite($fontSize)) {
            throw new InvalidArgumentException('An overlay font size must be a positive, finite number of points.');
        }
    }

    public function pageNumber(): int
    {
        return $this->page;
    }

    public function rectangle(): NativeRect
    {
        return $this->rect;
    }
}
