<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Contracts;

use App\Domain\Preparation\Text\TextExtractionException;
use App\Domain\Preparation\Text\TextRun;

/**
 * Extracts positioned text runs from a PDF, in native coordinates.
 *
 * Implementations interpret the page content streams. Running a regular expression
 * over raw or compressed PDF bytes is not an acceptable implementation, and neither
 * is fuzzy or model-based matching: anchor placement has to be reproducible.
 *
 * Extraction is deterministic. The same bytes must yield the same runs, in the same
 * order, with the same rectangles, on every run and every host.
 */
interface PdfTextLocator
{
    /**
     * @param  int|null  $page  1-based page number, or null for the whole document.
     * @return array<int, TextRun> In content-stream order, grouped by ascending page.
     *
     * @throws TextExtractionException
     */
    public function extract(string $pdfBytes, ?int $page = null): array;
}
