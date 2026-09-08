<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

/**
 * The configured seal certificate or private key is absent or unreadable.
 *
 * Covers an unconfigured deployment as well as a path that exists in
 * configuration but not on disk, or that the process cannot read.
 */
final class SealMaterialUnavailableException extends SealingException {}
