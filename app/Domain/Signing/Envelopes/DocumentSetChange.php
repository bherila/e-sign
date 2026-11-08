<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Preparation\Documents\DocumentSetEntry;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Signing\Exceptions\DocumentSetChangeRejected;

/**
 * One change to a draft's document set, computed from the set as it stands (issue #182).
 *
 * Each constructor takes the **current** set — read under the envelope lock by
 * {@see EnvelopeStateMachine::changeDocumentSet()} — and answers the set the change produces
 * and where every existing page goes. Pages are absolute across the set
 * (docs/preparation/document-sets.md), so a document that changes place moves the absolute
 * number of every page after it; `pageMap` is how the state machine carries each placed field
 * along with the page it is on. A page with no entry in the map is one the change removes.
 *
 * The constructors check what can be judged from the set alone: positions, ids, page counts,
 * page sizes. The state machine checks the rest — limits across the whole set, and fields on a
 * page that would disappear — because those need the locked envelope.
 */
final readonly class DocumentSetChange
{
    public const ADD = 'add';

    public const REORDER = 'reorder';

    public const REPLACE = 'replace';

    public const RENAME = 'rename';

    public const REMOVE = 'remove';

    /** Page sizes may differ by this much, in points, and still count as the same page. */
    public const DIMENSION_TOLERANCE_PT = 1.0;

    /**
     * @param  list<array{revision_id: int, title: string, sha256: string, page_count: int, public_id: string|null}>  $members  The set after the change, in order.
     * @param  array<int, int>  $pageMap  Current absolute page => absolute page after the change.
     * @param  string|null  $documentId  The document the change is about, when it names one.
     */
    private function __construct(
        public string $operation,
        public array $members,
        public array $pageMap,
        public bool $layoutChanged,
        public ?string $documentId,
    ) {}

    /**
     * Add a document at `$position` (1-based), or last.
     *
     * @throws DocumentSetChangeRejected
     */
    public static function add(DocumentSet $current, DocumentRevision $revision, string $title, ?int $position): self
    {
        $count = $current->count();
        $position ??= $count + 1;

        if ($position < 1 || $position > $count + 1) {
            throw DocumentSetChangeRejected::invalidPosition($position, $count + 1);
        }

        if ($count >= DocumentSet::MAX_DOCUMENTS) {
            throw DocumentSetChangeRejected::tooMany(DocumentSet::MAX_DOCUMENTS);
        }

        $pages = (int) $revision->page_count;

        if ($pages < 1) {
            throw DocumentSetChangeRejected::notReady((string) $revision->document?->public_id);
        }

        $sources = array_map(static fn (DocumentSetEntry $entry): ?int => $entry->position, $current->entries);
        array_splice($sources, $position - 1, 0, [null]);

        $members = [];

        foreach ($sources as $from) {
            $members[] = $from === null
                ? ['revision_id' => (int) $revision->getKey(), 'title' => $title, 'sha256' => $revision->sha256, 'page_count' => $pages, 'public_id' => null]
                : self::memberOf($current->entries[$from - 1]);
        }

        // Pages were added, so the layout changed even when nothing already placed moved.
        return new self(self::ADD, $members, self::pageMap($current, $sources, $members), true, null);
    }

    /**
     * Put the set in the order `$documentIds` names. Every document exactly once.
     *
     * @param  list<string>  $documentIds
     *
     * @throws DocumentSetChangeRejected
     */
    public static function reorder(DocumentSet $current, array $documentIds): self
    {
        $sources = [];

        foreach ($documentIds as $documentId) {
            $sources[] = self::entryById($current, $documentId)->position;
        }

        if (count(array_unique($sources)) !== count($sources)) {
            throw DocumentSetChangeRejected::invalidOrder('a document is named more than once.');
        }

        if (count($sources) !== $current->count()) {
            throw DocumentSetChangeRejected::invalidOrder(
                $current->count().' documents, and '.count($sources).' are named.',
            );
        }

        $members = array_map(static fn (int $from): array => self::memberOf($current->entries[$from - 1]), $sources);

        return new self(self::REORDER, $members, self::pageMap($current, $sources, $members), $sources !== range(1, $current->count()), null);
    }

    /**
     * Replace one document's bytes with another document of the same shape.
     *
     * Same number of pages and every page the same size within 1 pt, which is upstream's rule
     * and the reason it can be allowed at all: every field placed on the old document stays on
     * the same page at the same coordinates, and nothing else about the set moves.
     *
     * @throws DocumentSetChangeRejected
     */
    public static function replace(DocumentSet $current, string $documentId, DocumentRevision $revision): self
    {
        $entry = self::entryById($current, $documentId);
        $pages = (int) $revision->page_count;

        if ($pages < 1) {
            throw DocumentSetChangeRejected::notReady((string) $revision->document?->public_id);
        }

        if ($pages !== $entry->pageCount) {
            throw DocumentSetChangeRejected::pageCountMismatch($entry->pageCount, $pages);
        }

        self::assertSamePageSizes($entry, $revision);

        $members = array_map(static fn (DocumentSetEntry $member): array => self::memberOf($member), $current->entries);
        $members[$entry->position - 1] = [
            'revision_id' => (int) $revision->getKey(),
            'title' => $entry->title,
            'sha256' => $revision->sha256,
            'page_count' => $pages,
            'public_id' => $entry->publicId,
        ];

        return new self(self::REPLACE, $members, self::identity($current), false, $documentId);
    }

    /**
     * Give one document a new display name, or none.
     *
     * @throws DocumentSetChangeRejected
     */
    public static function rename(DocumentSet $current, string $documentId, ?string $label): self
    {
        $entry = self::entryById($current, $documentId);
        $members = array_map(static fn (DocumentSetEntry $member): array => self::memberOf($member), $current->entries);
        $members[$entry->position - 1]['title'] = $label ?? '';

        return new self(self::RENAME, $members, self::identity($current), false, $documentId);
    }

    /**
     * Remove one document. Never the last one.
     *
     * @throws DocumentSetChangeRejected
     */
    public static function remove(DocumentSet $current, string $documentId): self
    {
        $entry = self::entryById($current, $documentId);

        if ($current->count() === 1) {
            throw DocumentSetChangeRejected::minDocuments();
        }

        $sources = array_values(array_filter(
            array_map(static fn (DocumentSetEntry $member): int => $member->position, $current->entries),
            static fn (int $position): bool => $position !== $entry->position,
        ));
        $members = array_map(static fn (int $from): array => self::memberOf($current->entries[$from - 1]), $sources);

        return new self(self::REMOVE, $members, self::pageMap($current, $sources, $members), true, $documentId);
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * @return array{revision_id: int, title: string, sha256: string, page_count: int, public_id: string|null}
     */
    private static function memberOf(DocumentSetEntry $entry): array
    {
        return [
            'revision_id' => $entry->revisionId,
            'title' => $entry->title,
            'sha256' => $entry->sha256,
            'page_count' => $entry->pageCount,
            'public_id' => $entry->publicId,
        ];
    }

    /**
     * A set that has never been changed names its one document with no id, so it cannot be
     * addressed by one — upstream's `document_id: null`.
     *
     * @throws DocumentSetChangeRejected
     */
    private static function entryById(DocumentSet $current, string $documentId): DocumentSetEntry
    {
        foreach ($current->entries as $entry) {
            if ($entry->publicId !== null && hash_equals($entry->publicId, $documentId)) {
                return $entry;
            }
        }

        throw DocumentSetChangeRejected::notFound($documentId);
    }

    /**
     * Where each current page lands, given which current position each new member came from.
     *
     * @param  list<int|null>  $sources  Per new member, the current position it came from; null for a new document.
     * @param  list<array{page_count: int}>  $members
     * @return array<int, int>
     */
    private static function pageMap(DocumentSet $current, array $sources, array $members): array
    {
        $map = [];
        $firstPage = 1;

        foreach ($sources as $index => $from) {
            if ($from !== null) {
                $entry = $current->entries[$from - 1];

                for ($page = 0; $page < $entry->pageCount; $page++) {
                    $map[$entry->firstPage + $page] = $firstPage + $page;
                }
            }

            $firstPage += $members[$index]['page_count'];
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    private static function identity(DocumentSet $current): array
    {
        $pages = range(1, $current->totalPages());

        return array_combine($pages, $pages);
    }

    /**
     * @throws DocumentSetChangeRejected
     */
    private static function assertSamePageSizes(DocumentSetEntry $entry, DocumentRevision $replacement): void
    {
        $current = DocumentRevision::query()->with('document')->find($entry->revisionId);
        $before = $current instanceof DocumentRevision ? self::pageSizes($current) : null;
        $after = self::pageSizes($replacement);

        if ($before === null || $after === null || count($before) !== $entry->pageCount || count($after) !== $entry->pageCount) {
            throw DocumentSetChangeRejected::dimensionMismatch('the page sizes of one of the two documents could not be read.');
        }

        foreach ($before as $index => [$width, $height]) {
            [$newWidth, $newHeight] = $after[$index];

            if (abs($width - $newWidth) > self::DIMENSION_TOLERANCE_PT || abs($height - $newHeight) > self::DIMENSION_TOLERANCE_PT) {
                throw DocumentSetChangeRejected::dimensionMismatch(
                    'page '.($index + 1).' is '.round($width, 1).' x '.round($height, 1).' pt, and the replacement\'s is '
                    .round($newWidth, 1).' x '.round($newHeight, 1).' pt.',
                    ['page' => $index + 1],
                );
            }
        }
    }

    /**
     * Each page's displayed size, in the native space, from the preflight report intake recorded.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private static function pageSizes(DocumentRevision $revision): ?array
    {
        $report = $revision->document?->preflight_report;
        $pages = is_array($report) ? ($report['pages'] ?? null) : null;

        if (! is_array($pages)) {
            return null;
        }

        $sizes = [];

        foreach (array_values($pages) as $page) {
            if (! is_array($page) || ! is_numeric($page['native_width'] ?? null) || ! is_numeric($page['native_height'] ?? null)) {
                return null;
            }

            // `native_*` is already the displayed size, rotation applied: the one coordinate space
            // fields are placed in (AGENTS.md, "Coordinates are never guessed").
            $sizes[] = [(float) $page['native_width'], (float) $page['native_height']];
        }

        return $sizes;
    }
}
