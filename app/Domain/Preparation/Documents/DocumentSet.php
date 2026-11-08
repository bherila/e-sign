<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents;

use InvalidArgumentException;

/**
 * The ordered documents an envelope or a template version carries (issue #163).
 *
 * See docs/preparation/document-sets.md. A set of one is what every envelope had before sets
 * existed, and it stays exactly that: its {@see digest()} is the revision's own SHA-256, so an
 * existing envelope's `document_sha256`, and every attestation bound to it, keeps its meaning.
 * Two or more documents are bound by the set digest, which covers each member's review digest
 * in order, so moving, removing or replacing any one changes what an acceptance is bound to.
 *
 * Pages are absolute across the set: the first document's pages are 1..n, the second's start
 * at n+1. Nothing else records where one document ends, so nothing else can disagree.
 */
final readonly class DocumentSet
{
    /** Upstream's ceilings, per set. */
    public const MAX_DOCUMENTS = 20;

    public const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

    /** The canonical encoding the set digest is computed under. */
    public const ENCODING = 'esign.document_set.v1';

    /** @var list<DocumentSetEntry> */
    public array $entries;

    /**
     * @param  list<DocumentSetEntry>  $entries  In order; positions 1..n, pages contiguous from 1.
     */
    public function __construct(array $entries)
    {
        if ($entries === []) {
            throw new InvalidArgumentException('A document set has at least one document.');
        }

        $expectedPage = 1;

        foreach (array_values($entries) as $index => $entry) {
            if ($entry->position !== $index + 1 || $entry->firstPage !== $expectedPage || $entry->pageCount < 1) {
                throw new InvalidArgumentException(
                    'A document set\'s members are positioned 1..n with contiguous pages from 1; member '
                    .($index + 1).' is at position '.$entry->position.', first page '.$entry->firstPage.'.',
                );
            }

            $expectedPage += $entry->pageCount;
        }

        $this->entries = array_values($entries);
    }

    /**
     * Build a set from members in order, numbering positions and pages.
     *
     * @param  list<array{revision_id: int, title: string, sha256: string, page_count: int, public_id?: string|null}>  $members
     */
    public static function of(array $members): self
    {
        $entries = [];
        $page = 1;

        foreach (array_values($members) as $index => $member) {
            $entries[] = new DocumentSetEntry(
                position: $index + 1,
                revisionId: $member['revision_id'],
                title: $member['title'],
                sha256: $member['sha256'],
                pageCount: $member['page_count'],
                firstPage: $page,
                publicId: $member['public_id'] ?? null,
            );
            $page += $member['page_count'];
        }

        return new self($entries);
    }

    public function isMultiple(): bool
    {
        return count($this->entries) > 1;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function first(): DocumentSetEntry
    {
        return $this->entries[0];
    }

    public function totalPages(): int
    {
        $last = $this->entries[count($this->entries) - 1];

        return $last->lastPage();
    }

    /** The member an absolute page belongs to, or null for a page the set does not have. */
    public function entryForPage(int $absolutePage): ?DocumentSetEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->contains($absolutePage)) {
                return $entry;
            }
        }

        return null;
    }

    public function entryAt(int $position): ?DocumentSetEntry
    {
        return $this->entries[$position - 1] ?? null;
    }

    /**
     * What an acceptance binds: the revision's own digest for a set of one, the set digest
     * otherwise. See the class docblock.
     */
    public function digest(): string
    {
        if (! $this->isMultiple()) {
            return $this->first()->sha256;
        }

        // A fixed shape — two keys in a fixed order, integers and lowercase hex — so plain JSON
        // with these flags is already canonical; no key sorting or number formatting can vary.
        return hash('sha256', json_encode([
            'encoding' => self::ENCODING,
            'documents' => array_map(
                static fn (DocumentSetEntry $entry): array => ['position' => $entry->position, 'sha256' => $entry->sha256],
                $this->entries,
            ),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
