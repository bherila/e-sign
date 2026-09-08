<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

/**
 * Stable machine-readable reasons a document was rejected or flagged.
 *
 * These strings are part of the API contract: the Firma facade and the native API
 * both surface them, so they must not be renamed without a capability-matrix change.
 */
enum PreflightCode: string
{
    case Unparseable = 'unparseable';
    case Encrypted = 'encrypted';
    case AlreadySigned = 'already_signed';
    case JavaScript = 'javascript';
    case Xfa = 'xfa';
    case EmbeddedFile = 'embedded_file';
    case LaunchAction = 'launch_action';
    case NoPages = 'no_pages';
    case PageLimitExceeded = 'page_limit_exceeded';
    case ObjectLimitExceeded = 'object_limit_exceeded';
    case SizeLimitExceeded = 'size_limit_exceeded';
    case InvalidPageGeometry = 'invalid_page_geometry';
    case UserUnitNotPreserved = 'user_unit_not_preserved';
    case AnnotationsNotPreserved = 'annotations_not_preserved';
    case NonZeroCropBoxOrigin = 'non_zero_crop_box_origin';
}
