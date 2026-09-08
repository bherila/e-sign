<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * Converts a compatibility profile's declared coordinate convention into native space.
 *
 * Every conversion here is total and declared. A profile whose convention is unknown
 * or absent is an error, not a heuristic.
 */
final readonly class FacadeCoordinateTranslator
{
    /**
     * @param  string  $profile  Compatibility profile name, used only for error messages.
     *
     * @throws UndeclaredCoordinateConventionException
     */
    public function toNative(
        ?DeclaredCoordinateConvention $convention,
        PageGeometry $page,
        float $x,
        float $y,
        float $width,
        float $height,
        string $profile = 'unknown',
    ): NativeRect {
        if (! $convention instanceof DeclaredCoordinateConvention) {
            throw UndeclaredCoordinateConventionException::forProfile($profile);
        }

        $pageWidth = $page->nativeWidth();
        $pageHeight = $page->nativeHeight();

        return match ($convention) {
            DeclaredCoordinateConvention::NativePointsTopLeft => new NativeRect($x, $y, $width, $height),
            DeclaredCoordinateConvention::PercentOfPageTopLeft => new NativeRect(
                $x / 100.0 * $pageWidth,
                $y / 100.0 * $pageHeight,
                $width / 100.0 * $pageWidth,
                $height / 100.0 * $pageHeight,
            ),
            DeclaredCoordinateConvention::PointsBottomLeft => new NativeRect(
                $x,
                $pageHeight - $y - $height,
                $width,
                $height,
            ),
        };
    }

    /** Inverse of toNative(), so a facade response can echo back the caller's convention. */
    public function fromNative(
        ?DeclaredCoordinateConvention $convention,
        PageGeometry $page,
        NativeRect $rect,
        string $profile = 'unknown',
    ): NativeRect {
        if (! $convention instanceof DeclaredCoordinateConvention) {
            throw UndeclaredCoordinateConventionException::forProfile($profile);
        }

        $pageWidth = $page->nativeWidth();
        $pageHeight = $page->nativeHeight();

        return match ($convention) {
            DeclaredCoordinateConvention::NativePointsTopLeft => $rect,
            DeclaredCoordinateConvention::PercentOfPageTopLeft => new NativeRect(
                $rect->x / $pageWidth * 100.0,
                $rect->y / $pageHeight * 100.0,
                $rect->width / $pageWidth * 100.0,
                $rect->height / $pageHeight * 100.0,
            ),
            DeclaredCoordinateConvention::PointsBottomLeft => new NativeRect(
                $rect->x,
                $pageHeight - $rect->y - $rect->height,
                $rect->width,
                $rect->height,
            ),
        };
    }
}
