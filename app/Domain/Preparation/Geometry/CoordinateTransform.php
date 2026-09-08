<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * Converts between the native coordinate space and PDF default user space for one page.
 *
 * Native space: unit `pt`, origin at the top-left corner of the displayed CropBox,
 * x to the right, y downwards, page index 1-based, rotation already applied.
 *
 * User space: ISO 32000-1 default user space, origin at the bottom-left corner of the
 * MediaBox, y upwards, rotation not applied.
 *
 * See docs/preparation/coordinate-space.md for the normative statement of the space
 * and the reasoning behind the /UserUnit rule.
 */
final readonly class CoordinateTransform
{
    public function __construct(public PageGeometry $page) {}

    /**
     * Native point -> PDF user space.
     *
     * Two steps, both written out so the sign conventions stay reviewable:
     *   1. undo the display rotation, giving unrotated page coordinates measured
     *      from the top-left corner of the CropBox;
     *   2. flip the y axis and add the CropBox origin.
     */
    public function toUserSpace(NativePoint $point): UserSpacePoint
    {
        $crop = $this->page->cropBox;
        $cw = $crop->width();
        $ch = $crop->height();

        [$px, $py] = match ($this->page->rotation) {
            PageRotation::Clockwise90 => [$point->y, $ch - $point->x],
            PageRotation::Clockwise180 => [$cw - $point->x, $ch - $point->y],
            PageRotation::Clockwise270 => [$cw - $point->y, $point->x],
            PageRotation::None => [$point->x, $point->y],
        };

        return new UserSpacePoint($crop->x0 + $px, $crop->y0 + ($ch - $py));
    }

    /** PDF user space -> native point. Exact inverse of toUserSpace(). */
    public function toNative(UserSpacePoint $point): NativePoint
    {
        $crop = $this->page->cropBox;
        $cw = $crop->width();
        $ch = $crop->height();

        $px = $point->x - $crop->x0;
        $py = $ch - ($point->y - $crop->y0);

        [$nx, $ny] = match ($this->page->rotation) {
            PageRotation::Clockwise90 => [$ch - $py, $px],
            PageRotation::Clockwise180 => [$cw - $px, $ch - $py],
            PageRotation::Clockwise270 => [$py, $cw - $px],
            PageRotation::None => [$px, $py],
        };

        return new NativePoint($nx, $ny);
    }

    /**
     * Native rectangle -> the axis-aligned user-space rectangle it covers.
     *
     * Quarter turns keep axis-aligned rectangles axis-aligned, so mapping the two
     * opposite corners and re-normalising is exact, not an approximation.
     */
    public function rectToUserSpace(NativeRect $rect): UserSpaceRect
    {
        return UserSpaceRect::fromCorners(
            $this->toUserSpace($rect->topLeft()),
            $this->toUserSpace($rect->bottomRight()),
        );
    }

    /** User-space rectangle -> native rectangle. */
    public function rectToNative(UserSpaceRect $rect): NativeRect
    {
        return NativeRect::fromCorners(
            $this->toNative(new UserSpacePoint($rect->x0, $rect->y0)),
            $this->toNative(new UserSpacePoint($rect->x1, $rect->y1)),
        );
    }

    /**
     * Native length -> physical points (1/72 inch on paper).
     *
     * Native units are default user-space units, which /UserUnit scales. This
     * conversion is only ever applied when a caller explicitly asks for a physical
     * measurement; it never rescales stored field coordinates.
     */
    public function nativeToPhysicalPoints(float $native): float
    {
        return $native * $this->page->userUnit;
    }

    /** Physical points -> native length. */
    public function physicalPointsToNative(float $physical): float
    {
        return $physical / $this->page->userUnit;
    }

    /** Native rectangle expressed in physical points, for print-size reporting. */
    public function rectToPhysicalPoints(NativeRect $rect): NativeRect
    {
        $scale = $this->page->userUnit;

        return new NativeRect($rect->x * $scale, $rect->y * $scale, $rect->width * $scale, $rect->height * $scale);
    }

    /** True when the rectangle lies entirely inside the displayed page. */
    public function containsRect(NativeRect $rect, float $tolerance = 1.0e-6): bool
    {
        return $rect->x >= -$tolerance
            && $rect->y >= -$tolerance
            && $rect->right() <= $this->page->nativeWidth() + $tolerance
            && $rect->bottom() <= $this->page->nativeHeight() + $tolerance;
    }
}
