<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

/**
 * One member of a {@see DocumentSet}: which review revision, where its pages sit in the set.
 *
 * `firstPage` is absolute (1-based, counted across the whole set), which is what a field's
 * `page` means in a set; `localPage()` converts one back to the document's own numbering.
 */
final readonly class DocumentSetEntry
{
    public function __construct(
        public int $position,
        public int $revisionId,
        public string $title,
        public string $sha256,
        public int $pageCount,
        public int $firstPage,
        /** The set row's public id; null for the implicit set of one an envelope always had. */
        public ?string $publicId = null,
    ) {}

    public function lastPage(): int
    {
        return $this->firstPage + $this->pageCount - 1;
    }

    public function contains(int $absolutePage): bool
    {
        return $absolutePage >= $this->firstPage && $absolutePage <= $this->lastPage();
    }

    public function localPage(int $absolutePage): int
    {
        return $absolutePage - $this->firstPage + 1;
    }
}
