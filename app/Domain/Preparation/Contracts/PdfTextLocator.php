<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Contracts;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
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
     * @param  PreflightBudget|null  $budget  The running cost of reading this document, charged
     *                                        from the parse through the content walk. Preflight's
     *                                        ceilings bound the *document* — its size, object
     *                                        count and streams — and a file can satisfy all of
     *                                        them while holding millions of small text-showing
     *                                        operators in one allowed stream.
     *
     *                                        Supplying one means the caller is accounting for
     *                                        this document's cost and will be told when a ceiling
     *                                        stops it. Supplying none does not mean unbounded:
     *                                        the implementation still reads under the
     *                                        deployment's configured limits, but a caller that
     *                                        never asked to account for the cost is not handed an
     *                                        exception about it — it sees the same
     *                                        `TextExtractionException` as any other document it
     *                                        cannot read.
     * @return array<int, TextRun> In content-stream order, grouped by ascending page.
     *
     * @throws TextExtractionException When the bytes cannot be read as a document — a failed
     *                                 parse, an unreadable page tree, geometry that is not
     *                                 finite — or when a ceiling stops a read the caller supplied
     *                                 no budget for.
     * @throws PreflightBudgetException When a ceiling stops a read the caller *did* supply a
     *                                  budget for. Deliberately distinct: "too expensive to read"
     *                                  and "not a readable document" call for different answers
     *                                  from whoever uploaded it.
     */
    public function extract(string $pdfBytes, ?int $page = null, ?PreflightBudget $budget = null): array;
}
