<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

/**
 * A 2D affine transformation matrix in PDF order: [a b c d e f].
 *
 * Maps (x, y) to (a*x + c*y + e, b*x + d*y + f).
 */
final readonly class Matrix
{
    public function __construct(
        public float $a,
        public float $b,
        public float $c,
        public float $d,
        public float $e,
        public float $f,
    ) {}

    public static function identity(): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    public static function translation(float $dx, float $dy): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $dx, $dy);
    }

    public static function scaling(float $sx, float $sy): self
    {
        return new self($sx, 0.0, 0.0, $sy, 0.0, 0.0);
    }

    /** @param array<int, float> $values */
    public static function fromArray(array $values): self
    {
        $v = array_values($values);
        if (count($v) !== 6) {
            throw new \InvalidArgumentException('A transformation matrix needs exactly six numbers.');
        }

        return new self($v[0], $v[1], $v[2], $v[3], $v[4], $v[5]);
    }

    /** $this then $other, i.e. the matrix product $this x $other. */
    public function multiply(self $other): self
    {
        return new self(
            $this->a * $other->a + $this->b * $other->c,
            $this->a * $other->b + $this->b * $other->d,
            $this->c * $other->a + $this->d * $other->c,
            $this->c * $other->b + $this->d * $other->d,
            $this->e * $other->a + $this->f * $other->c + $other->e,
            $this->e * $other->b + $this->f * $other->d + $other->f,
        );
    }

    /** @return array{float, float} */
    public function apply(float $x, float $y): array
    {
        return [
            $this->a * $x + $this->c * $y + $this->e,
            $this->b * $x + $this->d * $y + $this->f,
        ];
    }

    /** Uniform-ish scale factors along each axis, used to size text runs. */
    public function scaleX(): float
    {
        return sqrt($this->a * $this->a + $this->b * $this->b);
    }

    public function scaleY(): float
    {
        return sqrt($this->c * $this->c + $this->d * $this->d);
    }

    /** True when the matrix has no rotation or skew component. */
    public function isAxisAligned(float $tolerance = 1.0e-9): bool
    {
        return abs($this->b) <= $tolerance && abs($this->c) <= $tolerance;
    }

    /** @return array{float, float, float, float, float, float} */
    public function toArray(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }
}
