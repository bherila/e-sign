<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * Displayed page sizes of the document a field set is being validated against.
 *
 * A field document carries no page geometry: it names a `document_id` and 1-based page numbers,
 * and the page count and displayed size come from the PDF. So the page-count and out-of-page
 * checks are only performed when a caller supplies this, and the validator says so in the
 * message rather than pretending a rectangle was checked when it was not.
 *
 * Sizes are in the same unit and orientation as the coordinate space: points, after /Rotate is
 * applied, measured on the CropBox. `App\Domain\Preparation\Geometry\PageGeometry` computes
 * exactly that (`nativeWidth()`/`nativeHeight()`), and this class is where those numbers arrive;
 * it does not recompute them.
 */
final readonly class PageSizes
{
    /**
     * @param  array<int, array{width: float, height: float}>  $sizes  1-based, contiguous.
     */
    private function __construct(private array $sizes) {}

    /**
     * @param  list<array{width: int|float, height: int|float}>  $sizes  In page order, page 1 first.
     */
    public static function fromList(array $sizes): self
    {
        $map = [];

        foreach ($sizes as $index => $size) {
            $map[$index + 1] = $size;
        }

        return self::fromMap($map);
    }

    /**
     * @param  array<int, array{width: int|float, height: int|float}>  $sizes  Keyed by 1-based page number.
     */
    public static function fromMap(array $sizes): self
    {
        if ($sizes === []) {
            throw new InvalidArgumentException('Page sizes must describe at least one page.');
        }

        $normalized = [];

        for ($page = 1; $page <= count($sizes); $page++) {
            if (! array_key_exists($page, $sizes)) {
                throw new InvalidArgumentException('Page sizes must be contiguous from page 1; page '.$page.' is missing.');
            }

            $width = (float) $sizes[$page]['width'];
            $height = (float) $sizes[$page]['height'];

            if (! is_finite($width) || ! is_finite($height) || $width <= 0.0 || $height <= 0.0) {
                throw new InvalidArgumentException('Page '.$page.' must have a positive finite width and height.');
            }

            $normalized[$page] = ['width' => $width, 'height' => $height];
        }

        return new self($normalized);
    }

    /** Every page the same size, the common single-size document. */
    public static function uniform(int $pageCount, float $width, float $height): self
    {
        if ($pageCount < 1) {
            throw new InvalidArgumentException('A document has at least one page; got '.$pageCount.'.');
        }

        return self::fromMap(array_fill(1, $pageCount, ['width' => $width, 'height' => $height]));
    }

    public function pageCount(): int
    {
        return count($this->sizes);
    }

    public function has(int $page): bool
    {
        return array_key_exists($page, $this->sizes);
    }

    /**
     * @return array{width: float, height: float}
     */
    public function of(int $page): array
    {
        if (! $this->has($page)) {
            throw new InvalidArgumentException('No size recorded for page '.$page.'.');
        }

        return $this->sizes[$page];
    }
}
