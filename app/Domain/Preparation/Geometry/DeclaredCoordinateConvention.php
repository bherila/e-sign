<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * A coordinate convention that an inbound request declares for itself.
 *
 * The convention is always read from an explicit declaration. It is never inferred
 * from the magnitude of a number: `{"x": 60}` is 60 pt under one profile and 60% of
 * the page under another, and the two are indistinguishable without the declaration.
 */
enum DeclaredCoordinateConvention: string
{
    /** Already native: pt, top-left origin, CropBox, displayed rotation. */
    case NativePointsTopLeft = 'native_points_top_left';

    /** 0-100 percentages of the displayed page, top-left origin. */
    case PercentOfPageTopLeft = 'percent_of_page_top_left';

    /** pt measured from the bottom-left corner of the displayed page. */
    case PointsBottomLeft = 'points_bottom_left';
}
