<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

use App\Domain\Preparation\Geometry\PageGeometry;

/**
 * The outcome of inspecting an uploaded PDF.
 *
 * A report is accepted only when it carries no finding of severity `reject`. Callers
 * must check `isAccepted()`; there is no partial-success path where an unsupported
 * structure is silently dropped.
 */
final readonly class PreflightReport
{
    /**
     * @param  array<int, PreflightFinding>  $findings
     * @param  array<int, PageGeometry>  $pages  Indexed from 0, page numbers are 1-based.
     */
    public function __construct(
        public array $findings,
        public array $pages,
        public DocumentMetrics $metrics,
    ) {}

    public function isAccepted(): bool
    {
        return $this->rejections() === [];
    }

    /** @return array<int, PreflightFinding> */
    public function rejections(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (PreflightFinding $f): bool => $f->severity === PreflightSeverity::Reject,
        ));
    }

    /** @return array<int, PreflightFinding> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (PreflightFinding $f): bool => $f->severity === PreflightSeverity::Warning,
        ));
    }

    /** @return array<int, string> */
    public function rejectionCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn (PreflightFinding $f): string => $f->code->value,
            $this->rejections(),
        )));
    }

    public function hasCode(PreflightCode $code): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return true;
            }
        }

        return false;
    }

    /** 1-based page lookup. */
    public function page(int $pageNumber): ?PageGeometry
    {
        foreach ($this->pages as $page) {
            if ($page->pageNumber === $pageNumber) {
                return $page;
            }
        }

        return null;
    }

    /** A single human-readable rejection message, suitable for an upload error. */
    public function rejectionMessage(): string
    {
        $messages = array_map(
            static fn (PreflightFinding $f): string => $f->message,
            $this->rejections(),
        );

        return implode(' ', $messages);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accepted' => $this->isAccepted(),
            'findings' => array_map(static fn (PreflightFinding $f): array => $f->toArray(), $this->findings),
            'pages' => array_map(static fn (PageGeometry $p): array => $p->toArray(), $this->pages),
            'metrics' => $this->metrics->toArray(),
        ];
    }
}
