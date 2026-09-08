<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

use App\Domain\Preparation\Geometry\NativeRect;
use InvalidArgumentException;

/**
 * A raster image drawn inside a native rectangle: in practice, a captured signature mark.
 *
 * The bytes are carried rather than a path or a URL. docs/HANDOFF.md section 8 requires that
 * signature submissions be rendered "through controlled assets" — decoded and re-encoded
 * within limits, never an arbitrary SVG and never a remote image — so by the time an overlay
 * exists the caller has already decided these bytes are acceptable, and the assembler must
 * not be able to reach out for anything else.
 *
 * The image is fitted inside the rectangle preserving its aspect ratio and centred, because
 * a signature stretched to a field's proportions is a different mark from the one the person
 * drew.
 */
final readonly class OverlayImage implements PageOverlay
{
    /**
     * @param  int  $page  1-based page number.
     * @param  NativeRect  $rect  The box to fit the image inside, in native coordinates.
     * @param  string  $bytes  Raw image bytes, already validated by the caller.
     */
    public function __construct(
        public int $page,
        public NativeRect $rect,
        public string $bytes,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Overlay page numbers are 1-based.');
        }

        if ($bytes === '') {
            throw new InvalidArgumentException('An image overlay needs image bytes.');
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
