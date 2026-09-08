<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * An axis-aligned rectangle in native space, anchored at its top-left corner.
 */
final readonly class NativeRect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        foreach ([$x, $y, $width, $height] as $value) {
            if (! is_finite($value)) {
                throw new InvalidGeometryException('Native rectangle values must be finite numbers.');
            }
        }

        if ($width < 0.0 || $height < 0.0) {
            throw new InvalidGeometryException('Native rectangle width and height must not be negative.');
        }
    }

    /** Build from two opposite corners in native space, in any order. */
    public static function fromCorners(NativePoint $a, NativePoint $b): self
    {
        return new self(
            min($a->x, $b->x),
            min($a->y, $b->y),
            abs($b->x - $a->x),
            abs($b->y - $a->y),
        );
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }

    public function bottom(): float
    {
        return $this->y + $this->height;
    }

    public function topLeft(): NativePoint
    {
        return new NativePoint($this->x, $this->y);
    }

    public function bottomRight(): NativePoint
    {
        return new NativePoint($this->right(), $this->bottom());
    }

    public function translated(float $dx, float $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->width, $this->height);
    }

    /** Smallest rectangle containing both. */
    public function union(self $other): self
    {
        $x = min($this->x, $other->x);
        $y = min($this->y, $other->y);

        return new self($x, $y, max($this->right(), $other->right()) - $x, max($this->bottom(), $other->bottom()) - $y);
    }

    public function equals(self $other, float $tolerance = 1.0e-6): bool
    {
        return abs($this->x - $other->x) <= $tolerance
            && abs($this->y - $other->y) <= $tolerance
            && abs($this->width - $other->width) <= $tolerance
            && abs($this->height - $other->height) <= $tolerance;
    }

    /** @return array{float, float, float, float} */
    public function toArray(): array
    {
        return [$this->x, $this->y, $this->width, $this->height];
    }
}
