<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * A rectangle that records where something *was*, in the declared native coordinate space.
 *
 * The counterpart to {@see Rect}, and the distinction is the whole point of the class. A `Rect`
 * is a *placement*: somewhere a field goes, so it must be on the page, must have a positive
 * width and height, and a negative coordinate is a bug. This is an *observation*: where a run of
 * text turned out to sit, measured from the font's own metrics, and nothing about the document
 * guarantees it is tidy.
 *
 * Two things follow, and both are real rather than theoretical:
 *
 * - **`x` and `y` may be negative.** A run's nominal box is its advance width by the font's
 *   ascent plus descent, and a heading near the top of the page routinely has an ascender that
 *   crosses the CropBox edge. Refusing that would refuse the document.
 * - **It is never checked against the page.** A run touching the right or bottom crop edge is a
 *   normal document, not an error, and it is not something anybody placed.
 *
 * Only `width` and `height` are constrained, to being non-negative, because a box with a negative
 * extent is not a measurement of anything.
 *
 * Canonicalised to three decimals like every other coordinate, so a receipt round trips byte for
 * byte ({@see CanonicalNumber}).
 */
final readonly class MeasuredRect
{
    public float $x;

    public float $y;

    public float $width;

    public float $height;

    public function __construct(float $x, float $y, float $width, float $height)
    {
        foreach (['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Measured rect.'.$name.' must be a finite number.');
            }
        }

        if ($width < 0.0 || $height < 0.0) {
            throw new InvalidArgumentException('Measured rect.width and rect.height must not be negative.');
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
