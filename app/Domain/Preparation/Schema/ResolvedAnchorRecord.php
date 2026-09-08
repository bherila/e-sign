<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use App\Domain\Preparation\Text\ResolvedAnchor;
use InvalidArgumentException;

/**
 * What resolution found, stored next to the anchor that asked for it.
 *
 * The anchor is provenance — the question the document asked — and the field's `rect` is the
 * answer everything downstream uses. This record is the receipt in between: which page the text
 * turned out to be on, which occurrence was taken, where the matched text itself sat, and what
 * rectangle came out of it. Keeping all three means a reader can tell an anchored field from a
 * hand-placed one *and* check the placement without re-running extraction, while assembly,
 * signing, and finalization keep reading nothing but `rect`.
 *
 * `document_sha256` is the digest of the exact bytes the text was extracted from. It is the
 * reason a stored rectangle can be trusted without re-resolving: a rectangle resolved against a
 * different revision is detectable rather than assumed, so an envelope built from another
 * revision resolves again at send instead of inheriting coordinates measured somewhere else.
 * After send nothing re-resolves at all (docs/preparation/anchors.md).
 *
 * A caller may submit one of these, and the validator checks it as strictly as anything else
 * rather than refusing it: an envelope re-reads its own stored schema through the same importer
 * on every request, so a receipt the service can write and not read back would be a document that
 * stops importing. A forged receipt is not a privilege: it would let a sender place a field at
 * coordinates of their choosing, which submitting a rectangle with no anchor already does.
 */
final readonly class ResolvedAnchorRecord
{
    public function __construct(
        public string $documentSha256,
        public int $page,
        public int $occurrenceIndex,
        public Rect $anchorRect,
        public Rect $rect,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $documentSha256) !== 1) {
            throw new InvalidArgumentException('anchor.resolved.document_sha256 must be 64 lowercase hexadecimal characters.');
        }

        if ($page < 1) {
            throw new InvalidArgumentException('anchor.resolved.page is 1-based.');
        }

        if ($occurrenceIndex < 1) {
            throw new InvalidArgumentException('anchor.resolved.occurrence_index is 1-based.');
        }
    }

    public static function fromResolvedAnchor(ResolvedAnchor $resolved, string $documentSha256): self
    {
        return new self(
            $documentSha256,
            $resolved->page,
            $resolved->occurrenceIndex,
            new Rect(
                $resolved->anchorRect->x,
                $resolved->anchorRect->y,
                $resolved->anchorRect->width,
                $resolved->anchorRect->height,
            ),
            new Rect(
                $resolved->resolvedRect->x,
                $resolved->resolvedRect->y,
                $resolved->resolvedRect->width,
                $resolved->resolvedRect->height,
            ),
        );
    }

    /**
     * @param  array{document_sha256: string, page: int, occurrence_index: int, anchor_rect: array{x: int|float, y: int|float, width: int|float, height: int|float}, rect: array{x: int|float, y: int|float, width: int|float, height: int|float}}  $resolved
     */
    public static function fromArray(array $resolved): self
    {
        return new self(
            $resolved['document_sha256'],
            (int) $resolved['page'],
            (int) $resolved['occurrence_index'],
            Rect::fromArray($resolved['anchor_rect']),
            Rect::fromArray($resolved['rect']),
        );
    }

    /** True when this receipt was written against the bytes the caller holds. */
    public function describes(string $documentSha256): bool
    {
        return hash_equals($this->documentSha256, $documentSha256);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document_sha256' => $this->documentSha256,
            'page' => $this->page,
            'occurrence_index' => $this->occurrenceIndex,
            'anchor_rect' => $this->anchorRect->toArray(),
            'rect' => $this->rect->toArray(),
        ];
    }
}
