<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

use RuntimeException;

/** Raised when a document cannot be imported and re-assembled. */
class AssemblyException extends RuntimeException {}
