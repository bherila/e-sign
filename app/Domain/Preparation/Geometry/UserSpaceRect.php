<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * An axis-aligned rectangle in PDF default user space, stored as opposite corners.
 */
final readonly class UserSpaceRect
{
    public float $x0;

    public float $y0;

    public float $x1;

    public float $y1;

    public function __construct(float $x0, float $y0, float $x1, float $y1)
    {
        foreach ([$x0, $y0, $x1, $y1] as $value) {
            if (! is_finite($value)) {
                throw new InvalidGeometryException('User-space rectangle values must be finite numbers.');
            }
        }

        $this->x0 = min($x0, $x1);
        $this->y0 = min($y0, $y1);
        $this->x1 = max($x0, $x1);
        $this->y1 = max($y0, $y1);
    }

    public static function fromCorners(UserSpacePoint $a, UserSpacePoint $b): self
    {
        return new self($a->x, $a->y, $b->x, $b->y);
    }

    public function width(): float
    {
        return $this->x1 - $this->x0;
    }

    public function height(): float
    {
        return $this->y1 - $this->y0;
    }

    public function equals(self $other, float $tolerance = 1.0e-6): bool
    {
        return abs($this->x0 - $other->x0) <= $tolerance
            && abs($this->y0 - $other->y0) <= $tolerance
            && abs($this->x1 - $other->x1) <= $tolerance
            && abs($this->y1 - $other->y1) <= $tolerance;
    }

    /** @return array{float, float, float, float} */
    public function toArray(): array
    {
        return [$this->x0, $this->y0, $this->x1, $this->y1];
    }
}
