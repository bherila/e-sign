<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use RuntimeException;

/** The /Pages tree could not be walked into a flat, usable list of pages. */
final class MalformedPageTreeException extends RuntimeException {}
