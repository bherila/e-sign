<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Exceptions;

use RuntimeException;

/**
 * Base type for every sealing failure.
 *
 * Sealing fails closed: any subclass reaching a caller means no artifact was
 * produced and none may be published. There is no partial or downgraded
 * result, and no code path turns one of these into a warning.
 */
abstract class SealingException extends RuntimeException {}
