<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/**
 * The corner of the matched text box that the anchor offset is measured from.
 *
 * Declared per anchor. Nothing is inferred from the sign of the offset.
 */
enum AnchorOrigin: string
{
    case TopLeft = 'top_left';
    case TopRight = 'top_right';
    case BottomLeft = 'bottom_left';
    case BottomRight = 'bottom_right';
}
