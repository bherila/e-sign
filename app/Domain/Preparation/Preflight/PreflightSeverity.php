<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

enum PreflightSeverity: string
{
    /** The document cannot be prepared. Upload is refused. */
    case Reject = 'reject';

    /** The document is usable, but something about it is not carried through assembly. */
    case Warning = 'warning';
}
