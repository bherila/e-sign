<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Exceptions;

use RuntimeException;

/** Raised when a finalization attempt cannot produce a publishable artifact. */
class FinalizationException extends RuntimeException {}
