<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * A point in PDF default user space: origin at the bottom-left of the MediaBox, y upwards.
 */
final readonly class UserSpacePoint
{
    public function __construct(public float $x, public float $y)
    {
        if (! is_finite($x) || ! is_finite($y)) {
            throw new InvalidGeometryException('User-space coordinates must be finite numbers.');
        }
    }

    /** @return array{float, float} */
    public function toArray(): array
    {
        return [$this->x, $this->y];
    }
}
