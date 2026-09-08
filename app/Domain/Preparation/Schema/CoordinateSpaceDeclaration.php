<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * The coordinate space a field document declares.
 *
 * Every document states its convention explicitly and version 1.0 implements exactly one:
 * points, top-left origin, CropBox (clipped to MediaBox), displayed rotation, 1-based pages.
 * A document that declares anything else is rejected, never reinterpreted — "coordinates are
 * never guessed" (AGENTS.md), and no code here infers points versus percent from magnitude.
 *
 * This class only *records and checks the declaration*. Converting a native rectangle into PDF
 * user space, applying CropBox offsets, /Rotate, and /UserUnit, belongs to
 * `App\Domain\Preparation\Geometry` and is deliberately not duplicated here: this module stores
 * plain numbers and hands them over. The five constants below must stay identical to
 * `Geometry\CoordinateSpace`, which is the transform-side authority; a test in
 * `tests/Unit/Preparation/Schema` pins them against `resources/schema/field-schema-1.0.json`
 * so the JSON contract and the PHP importer cannot drift apart.
 */
final readonly class CoordinateSpaceDeclaration
{
    public const UNIT = 'pt';

    public const ORIGIN = 'top-left';

    public const PAGE_BOX = 'crop';

    public const ROTATION = 'displayed';

    public const PAGE_INDEX_BASE = 1;

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
        return new self(self::UNIT, self::ORIGIN, self::PAGE_BOX, self::ROTATION, self::PAGE_INDEX_BASE);
    }

    /**
     * The expected declaration, in canonical key order.
     *
     * @return array{unit: string, origin: string, page_box: string, rotation: string, page_index_base: int}
     */
    public static function expected(): array
    {
        return [
            'unit' => self::UNIT,
            'origin' => self::ORIGIN,
            'page_box' => self::PAGE_BOX,
            'rotation' => self::ROTATION,
            'page_index_base' => self::PAGE_INDEX_BASE,
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
