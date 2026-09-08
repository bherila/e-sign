<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Assembly;

/**
 * Raised when assembly is attempted on a source the engine cannot faithfully
 * reproduce (encrypted input, or anything preflight already rejected).
 */
final class UnsupportedSourceException extends AssemblyException {}
