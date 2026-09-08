<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

use App\Domain\Preparation\TcPdf\TcPdfPreflight;

/**
 * A resource ceiling tripped while the document was being read.
 *
 * Thrown from inside the parser rather than checked after it, so the work stops at the
 * moment the budget is crossed instead of after the document has already been
 * materialised. {@see TcPdfPreflight} turns it into a
 * rejection carrying {@see self::$preflightCode}; it is never allowed to degrade into a
 * generic `unparseable`, because "this file is a decompression bomb" and "this file is
 * corrupt" call for different answers from the person who uploaded it.
 *
 * The message names the *limit*, never the running total: a count that says how far the
 * parser got is a measuring instrument for whoever built the file.
 */
final class PreflightBudgetException extends \RuntimeException
{
    public function __construct(
        public readonly PreflightCode $preflightCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
