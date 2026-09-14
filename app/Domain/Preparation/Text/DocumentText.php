<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Geometry\PageGeometry;

/**
 * A document's positioned text and its pages, from one read of it.
 *
 * For a caller that needs both. Anchor resolution cannot tell whether a placed field lands on its
 * page without the page's size, and when a revision's stored report carries none the size has to
 * come from the bytes. Asking {@see PdfTextLocator} for the text and then for the pages parses and
 * decodes the document twice on one budget, so every decoded byte is charged twice. A document
 * well inside `max_decompressed_bytes` could be refused on the second pass for a cost it never
 * had.
 */
final readonly class DocumentText
{
    /**
     * @param  list<TextRun>  $runs  In content-stream order, grouped by ascending page.
     * @param  list<PageGeometry>  $pages  Every page, in page order.
     */
    public function __construct(
        public array $runs,
        public array $pages,
    ) {}
}
