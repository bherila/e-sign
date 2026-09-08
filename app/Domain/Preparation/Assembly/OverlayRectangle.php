<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

use App\Domain\Preparation\Geometry\NativeRect;

/**
 * A rectangle to draw on an imported page, positioned in native coordinates.
 *
 * Stage 0 uses this as the probe for import geometry: a field appearance is placed
 * the same way, so if the rectangle lands correctly a signature box will too.
 */
final readonly class OverlayRectangle implements PageOverlay
{
    /**
     * @param  int  $page  1-based page number in the source document.
     * @param  array{float, float, float}  $strokeRgb  Components in 0..1.
     */
    public function __construct(
        public int $page,
        public NativeRect $rect,
        public array $strokeRgb = [1.0, 0.0, 0.0],
        public float $lineWidth = 1.0,
        public ?string $label = null,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException('Overlay page numbers are 1-based.');
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
