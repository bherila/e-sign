<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * The /Rotate value of a page, normalised to the four legal quarter turns.
 *
 * The angle is a clockwise rotation applied when the page is displayed, so the
 * native coordinate space is defined on the rotated result, not on the stored one.
 */
enum PageRotation: int
{
    case None = 0;
    case Clockwise90 = 90;
    case Clockwise180 = 180;
    case Clockwise270 = 270;

    /**
     * Normalise any multiple of 90 (including negatives and values above 360).
     *
     * @throws InvalidGeometryException When the angle is not a multiple of 90.
     */
    public static function fromDegrees(int $degrees): self
    {
        if ($degrees % 90 !== 0) {
            throw new InvalidGeometryException(
                'Page /Rotate must be a multiple of 90 degrees, got '.$degrees.'.',
            );
        }

        return self::from((($degrees % 360) + 360) % 360);
    }

    /** True when the rotation exchanges the displayed width and height. */
    public function swapsAxes(): bool
    {
        return $this === self::Clockwise90 || $this === self::Clockwise270;
    }
}
