<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * A PDF page boundary rectangle in default user space (origin bottom-left, y up).
 *
 * Corners are stored normalised: x0/y0 is always the lower-left corner. ISO 32000-1
 * allows either diagonal to be written first, so normalising here removes a whole
 * class of sign errors downstream.
 */
final readonly class PageBox
{
    public float $x0;

    public float $y0;

    public float $x1;

    public float $y1;

    public function __construct(float $x0, float $y0, float $x1, float $y1)
    {
        foreach ([$x0, $y0, $x1, $y1] as $value) {
            if (! is_finite($value)) {
                throw new InvalidGeometryException('Page box coordinates must be finite numbers.');
            }
        }

        $this->x0 = min($x0, $x1);
        $this->y0 = min($y0, $y1);
        $this->x1 = max($x0, $x1);
        $this->y1 = max($y0, $y1);

        if ($this->x1 - $this->x0 <= 0.0 || $this->y1 - $this->y0 <= 0.0) {
            throw new InvalidGeometryException('Page box must have a positive width and height.');
        }
    }

    /** @param array<int, float|int|string> $values */
    public static function fromArray(array $values): self
    {
        if (count($values) !== 4) {
            throw new InvalidGeometryException('A page box needs exactly four numbers.');
        }

        $numbers = array_values(array_map(static fn (float|int|string $v): float => (float) $v, $values));

        return new self($numbers[0], $numbers[1], $numbers[2], $numbers[3]);
    }

    public function width(): float
    {
        return $this->x1 - $this->x0;
    }

    public function height(): float
    {
        return $this->y1 - $this->y0;
    }

    /**
     * ISO 32000-1 9.10.1: a CropBox is clipped to the MediaBox. Returns null when
     * the two boxes do not overlap at all, which is a malformed page.
     */
    public function intersect(self $other): ?self
    {
        $x0 = max($this->x0, $other->x0);
        $y0 = max($this->y0, $other->y0);
        $x1 = min($this->x1, $other->x1);
        $y1 = min($this->y1, $other->y1);

        if ($x1 - $x0 <= 0.0 || $y1 - $y0 <= 0.0) {
            return null;
        }

        return new self($x0, $y0, $x1, $y1);
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
