<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Schema\PageSizes;

/**
 * A document set's pages, as one run numbered across the set (docs/preparation/document-sets.md).
 *
 * Each member's preflight report already describes its pages; this reads them in set order and
 * renumbers them so page n of the result is page n of the set. Everything that reasons about
 * pages — schema geometry, the signing page, the editor — reads this rather than a document's
 * own report, so a set and a single document are handled by the same code.
 */
final readonly class DocumentSetPages
{
    /**
     * The set's preflight page entries, renumbered absolutely. Empty when any member's report
     * cannot be read, so a caller skips its size-dependent checks rather than running them over
     * part of the set.
     *
     * @return list<array<string, mixed>>
     */
    public static function preflightPages(DocumentSet $set): array
    {
        $revisions = DocumentRevision::query()
            ->with('document')
            ->whereIn('id', array_map(static fn (DocumentSetEntry $entry): int => $entry->revisionId, $set->entries))
            ->get()
            ->keyBy('id');

        $pages = [];

        foreach ($set->entries as $entry) {
            $report = $revisions->get($entry->revisionId)?->document?->preflight_report;
            $own = is_array($report) ? ($report['pages'] ?? null) : null;

            if (! is_array($own) || count($own) !== $entry->pageCount) {
                return [];
            }

            foreach (array_values($own) as $index => $page) {
                if (! is_array($page)) {
                    return [];
                }

                $pages[] = ['page' => $entry->firstPage + $index] + $page;
            }
        }

        return $pages;
    }

    public static function pageSizes(DocumentSet $set): ?PageSizes
    {
        return PreflightPageSizes::ofPages(self::preflightPages($set));
    }
}
