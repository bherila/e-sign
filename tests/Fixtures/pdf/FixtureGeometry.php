<?php

declare(strict_types=1);

namespace Tests\Fixtures\Pdf;

/**
 * Coordinate helpers for the fixture generator.
 *
 * These are written out independently of app/Domain/Preparation/Geometry on purpose:
 * the fixture manifest must not be derived from the implementation it is used to check.
 * The unit tests for the domain transform use separately hand-computed literals.
 */
final class FixtureGeometry
{
    /**
     * Map a native point (pt, origin top-left of the displayed CropBox, y down)
     * to PDF user space (origin bottom-left of the MediaBox).
     *
     * @param  array{float, float, float, float}  $cropBox  [x0, y0, x1, y1] in user space
     * @return array{float, float}
     */
    public static function nativeToUser(float $nx, float $ny, array $cropBox, int $rotation): array
    {
        [$cx0, $cy0, $cx1, $cy1] = $cropBox;
        $cw = $cx1 - $cx0;
        $ch = $cy1 - $cy0;

        // Step 1: displayed coordinates -> unrotated page coordinates (top-left origin).
        [$px, $py] = match ((($rotation % 360) + 360) % 360) {
            90 => [$ny, $ch - $nx],
            180 => [$cw - $nx, $ch - $ny],
            270 => [$cw - $ny, $nx],
            default => [$nx, $ny],
        };

        // Step 2: unrotated top-left page coordinates -> user space.
        return [$cx0 + $px, $cy0 + ($ch - $py)];
    }

    /**
     * Map a native rectangle to the axis-aligned user-space rectangle it covers.
     *
     * @param  array{float, float, float, float}  $cropBox
     * @return array{float, float, float, float} [x0, y0, x1, y1] in user space
     */
    public static function nativeRectToUser(float $nx, float $ny, float $nw, float $nh, array $cropBox, int $rotation): array
    {
        [$ax, $ay] = self::nativeToUser($nx, $ny, $cropBox, $rotation);
        [$bx, $by] = self::nativeToUser($nx + $nw, $ny + $nh, $cropBox, $rotation);

        return [min($ax, $bx), min($ay, $by), max($ax, $bx), max($ay, $by)];
    }

    /**
     * Map a user-space rectangle back to a native rectangle [x, y, width, height].
     *
     * @param  array{float, float, float, float}  $cropBox
     * @return array{float, float, float, float}
     */
    public static function userRectToNative(float $ux0, float $uy0, float $ux1, float $uy1, array $cropBox, int $rotation): array
    {
        $corners = [
            self::userToNative($ux0, $uy0, $cropBox, $rotation),
            self::userToNative($ux1, $uy1, $cropBox, $rotation),
        ];
        $xs = [$corners[0][0], $corners[1][0]];
        $ys = [$corners[0][1], $corners[1][1]];

        return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
    }

    /**
     * @param  array{float, float, float, float}  $cropBox
     * @return array{float, float}
     */
    public static function userToNative(float $ux, float $uy, array $cropBox, int $rotation): array
    {
        [$cx0, $cy0, $cx1, $cy1] = $cropBox;
        $cw = $cx1 - $cx0;
        $ch = $cy1 - $cy0;

        $px = $ux - $cx0;
        $py = $ch - ($uy - $cy0);

        return match ((($rotation % 360) + 360) % 360) {
            90 => [$ch - $py, $px],
            180 => [$cw - $px, $ch - $py],
            270 => [$py, $cw - $px],
            default => [$px, $py],
        };
    }

    /** @return array{float, float} Displayed page size in native units. */
    public static function displayedSize(float $cropWidth, float $cropHeight, int $rotation): array
    {
        $normalised = (($rotation % 360) + 360) % 360;

        return ($normalised === 90 || $normalised === 270)
            ? [$cropHeight, $cropWidth]
            : [$cropWidth, $cropHeight];
    }
}
