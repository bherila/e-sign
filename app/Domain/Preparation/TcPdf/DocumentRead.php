<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\Parsing\FlattenedPage;
use App\Domain\Preparation\TcPdf\Parsing\MalformedPageTreeException;
use App\Domain\Preparation\TcPdf\Parsing\PageTreeReader;
use App\Domain\Preparation\TcPdf\Parsing\PdfObjectGraph;

/**
 * One read of one document: what it is allowed to cost, and what it is allowed to be.
 *
 * Every module that reads a PDF — preflight, text extraction, assembly — goes through this
 * class, because the two things that must not be decided per caller are exactly the two things
 * that were: *admission* (is this document allowed to be read at all) and *accounting* (whose
 * budget pays for reading it). Text extraction called straight from the Firma facade was
 * parsing a document of any size, because the byte ceiling lived inside preflight and nothing
 * made a caller run preflight first.
 *
 * Nothing is read until it is admitted, and the parse happens once: a second reader of the same
 * document gets the graph that was already built, charged once, rather than a second parse whose
 * cost nobody counted.
 *
 * The budget is the caller's when the caller has one, and this class's when it does not. That
 * distinction is *not* about what is allowed — the ceilings are the deployment's either way —
 * but about who is told when one is reached; see {@see TcPdfTextLocator::extract()}.
 */
final class DocumentRead
{
    private ?PdfObjectGraph $graph = null;

    /** @var array<int, FlattenedPage>|null */
    private ?array $pages = null;

    private function __construct(
        private readonly string $bytes,
        public readonly PreflightBudget $budget,
    ) {}

    /**
     * A read charged to a budget the caller already owns.
     *
     * The caller's ceilings are applied, including its byte ceiling: a budget built for a
     * smaller document does not become larger by being handed to a reader.
     */
    public static function on(string $bytes, PreflightBudget $budget): self
    {
        return new self($bytes, $budget);
    }

    /**
     * A read this class owns, on the given ceilings.
     *
     * @param  (\Closure(): float)|null  $clock  Passed to the budget, so a test can make a
     *                                           backstop trip without sleeping.
     */
    public static function under(string $bytes, PreflightLimits $limits, ?\Closure $clock = null): self
    {
        return new self($bytes, new PreflightBudget($limits, $clock));
    }

    /**
     * The parsed object graph, built once and charged to this read's budget.
     *
     * @throws PreflightBudgetException When the document is not admitted, or a ceiling is
     *                                  reached while it is read.
     * @throws \Throwable When the bytes are not a PDF this parser can read.
     */
    public function graph(): PdfObjectGraph
    {
        if (! $this->graph instanceof PdfObjectGraph) {
            $this->admit();
            $this->graph = PdfObjectGraph::parse($this->bytes, $this->budget);
        }

        return $this->graph;
    }

    /**
     * The document's pages, flattened, walked once under this read's page ceiling.
     *
     * @return array<int, FlattenedPage>
     *
     * @throws MalformedPageTreeException When the tree does not describe pages.
     * @throws PreflightBudgetException
     */
    public function pages(): array
    {
        return $this->pages ??= (new PageTreeReader($this->graph()))->pages($this->budget);
    }

    /**
     * The size ceiling, applied here rather than by whoever remembered to run preflight.
     *
     * Checked before the parse and not after it: the point of a byte ceiling is to refuse the
     * document before anything has been spent on it.
     *
     * @throws PreflightBudgetException
     */
    private function admit(): void
    {
        $limit = $this->budget->limits->maxBytes;

        if ($limit > 0 && strlen($this->bytes) > $limit) {
            $this->budget->exhaustBytes(strlen($this->bytes));
        }
    }
}
