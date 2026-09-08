<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use RuntimeException;

/** Raised when a document's text cannot be extracted deterministically. */
final class TextExtractionException extends RuntimeException {}
