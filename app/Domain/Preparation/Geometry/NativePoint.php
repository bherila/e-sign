<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * A point in the native coordinate space: pt, origin at the top-left corner of the
 * displayed CropBox, x to the right, y downwards.
 */
final readonly class NativePoint
{
    public function __construct(public float $x, public float $y)
    {
        if (! is_finite($x) || ! is_finite($y)) {
            throw new InvalidGeometryException('Native coordinates must be finite numbers.');
        }
    }

    /** @return array{float, float} */
    public function toArray(): array
    {
        return [$this->x, $this->y];
    }
}
