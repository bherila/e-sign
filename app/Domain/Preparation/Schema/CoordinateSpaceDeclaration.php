<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Geometry\CoordinateSpace;

/**
 * The coordinate space a field document declares.
 *
 * Every document states its convention explicitly and version 1.0 implements exactly one:
 * points, top-left origin, CropBox (clipped to MediaBox), displayed rotation, 1-based pages.
 * A document that declares anything else is rejected, never reinterpreted — "coordinates are
 * never guessed" (AGENTS.md), and no code here infers points versus percent from magnitude.
 *
 * The values come from {@see CoordinateSpace}, which is the module's authority on the space and
 * owns every transform into PDF user space (CropBox offsets, `/Rotate`, `/UserUnit`). This class
 * only records and checks the *declaration* so the schema can store plain numbers; it does not
 * define the space and it does not convert anything. See docs/preparation/coordinate-space.md.
 */
final readonly class CoordinateSpaceDeclaration
{
    public function __construct(
        public string $unit,
        public string $origin,
        public string $pageBox,
        public string $rotation,
        public int $pageIndexBase,
    ) {}

    /** The one space this version implements. */
    public static function native(): self
    {
        return new self(
            CoordinateSpace::UNIT,
            CoordinateSpace::ORIGIN,
            CoordinateSpace::PAGE_BOX,
            CoordinateSpace::ROTATION,
            CoordinateSpace::PAGE_INDEX_BASE,
        );
    }

    /**
     * The expected declaration, in canonical key order.
     *
     * @return array{unit: string, origin: string, page_box: string, rotation: string, page_index_base: int}
     */
    public static function expected(): array
    {
        return [
            'unit' => CoordinateSpace::UNIT,
            'origin' => CoordinateSpace::ORIGIN,
            'page_box' => CoordinateSpace::PAGE_BOX,
            'rotation' => CoordinateSpace::ROTATION,
            'page_index_base' => CoordinateSpace::PAGE_INDEX_BASE,
        ];
    }

    /**
     * @param  array{unit: string, origin: string, page_box: string, rotation: string, page_index_base: int}  $space
     */
    public static function fromArray(array $space): self
    {
        return new self(
            $space['unit'],
            $space['origin'],
            $space['page_box'],
            $space['rotation'],
            (int) $space['page_index_base'],
        );
    }

    public function isNative(): bool
    {
        return $this->toArray() === self::expected();
    }

    /**
     * @return array{unit: string, origin: string, page_box: string, rotation: string, page_index_base: int}
     */
    public function toArray(): array
    {
        return [
            'unit' => $this->unit,
            'origin' => $this->origin,
            'page_box' => $this->pageBox,
            'rotation' => $this->rotation,
            'page_index_base' => $this->pageIndexBase,
        ];
    }
}
