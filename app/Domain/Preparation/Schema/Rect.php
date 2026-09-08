<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * A field rectangle in the declared native coordinate space, anchored at its top-left corner.
 *
 * Plain numbers, canonicalised to three decimals on construction ({@see CanonicalNumber}).
 * This is the storage and transport shape only: turning it into PDF user space is
 * `App\Domain\Preparation\Geometry`'s job, and this class deliberately has no transform on it.
 *
 * Construction throws for values that should already have been rejected. The reporting path is
 * {@see FieldSchemaValidator}, which collects every bad coordinate with a code and a pointer
 * instead of throwing on the first one; these guards exist so a value object built directly in
 * code cannot smuggle a NaN into a document.
 */
final readonly class Rect
{
    public float $x;

    public float $y;

    public float $width;

    public float $height;

    public function __construct(float $x, float $y, float $width, float $height)
    {
        foreach (['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('rect.'.$name.' must be a finite number.');
            }
        }

        if ($x < 0.0 || $y < 0.0) {
            throw new InvalidArgumentException('rect.x and rect.y must not be negative in native space.');
        }

        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('rect.width and rect.height must be greater than zero.');
        }

        $this->x = CanonicalNumber::round($x);
        $this->y = CanonicalNumber::round($y);
        $this->width = CanonicalNumber::round($width);
        $this->height = CanonicalNumber::round($height);
    }

    /**
     * @param  array{x: int|float, y: int|float, width: int|float, height: int|float}  $rect
     */
    public static function fromArray(array $rect): self
    {
        return new self(
            (float) $rect['x'],
            (float) $rect['y'],
            (float) $rect['width'],
            (float) $rect['height'],
        );
    }

    public function right(): float
    {
        return CanonicalNumber::round($this->x + $this->width);
    }

    public function bottom(): float
    {
        return CanonicalNumber::round($this->y + $this->height);
    }

    /** Whether the rectangle fits inside a page of this size, within the canonical tolerance. */
    public function fitsWithin(float $pageWidth, float $pageHeight): bool
    {
        return $this->right() <= $pageWidth + CanonicalNumber::TOLERANCE
            && $this->bottom() <= $pageHeight + CanonicalNumber::TOLERANCE;
    }

    /**
     * @return array{x: int|float, y: int|float, width: int|float, height: int|float}
     */
    public function toArray(): array
    {
        return [
            'x' => CanonicalNumber::encode($this->x),
            'y' => CanonicalNumber::encode($this->y),
            'width' => CanonicalNumber::encode($this->width),
            'height' => CanonicalNumber::encode($this->height),
        ];
    }
}
