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
 * The two rectangles are deliberately different types. `rect` is a {@see Rect}: a placement, on
 * the page, positive in both extents. `anchor_rect` is a {@see MeasuredRect}: an observation of
 * where a run of text sat, which may legitimately start above the top of the CropBox — a heading's
 * ascender does — and is never checked against the page edge. Validating a measurement as though
 * it were a placement is how a perfectly ordinary document becomes an unimportable one.
 *
 * `document_sha256` is the digest of the exact bytes the text was extracted from, and it is
 * provenance rather than a cache key. A receipt never lets resolution be skipped: publishing and
 * sending both resolve every anchored field, because a receipt binds its rectangle to a document,
 * a page and an occurrence but not to the anchor *text*, the origin corner or the offset — so
 * trusting one would let an edited request keep the answer to the question it used to ask. What
 * the digest is for is reading a stored document afterwards: it says which bytes the recorded
 * rectangle was measured in, so a receipt from another revision is recognisable as one rather
 * than mistaken for a measurement of this one. After send nothing re-resolves at all
 * (docs/preparation/anchors.md).
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
        public MeasuredRect $anchorRect,
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

        // `rect` is a {@see Rect}, which is 1.0's unbounded placement type, but this member is
        // 1.1's `$defs/resolved_rect` and the validator bounds it. Without the same bound here a
        // receipt could be written that the very next import refuses — the service emitting a
        // document it cannot read back. Judged on the canonical value because that is what will
        // be stored and therefore what the validator will see: refusing a raw 14400.0004 that is
        // written down as 14400 would make the two disagree in the other direction.
        foreach (['x' => $rect->x, 'y' => $rect->y, 'width' => $rect->width, 'height' => $rect->height] as $name => $value) {
            if (abs(CanonicalNumber::round($value)) > MeasuredRect::MAX_MAGNITUDE) {
                throw new InvalidArgumentException(
                    'anchor.resolved.rect.'.$name.' must be within '.MeasuredRect::MAX_MAGNITUDE
                        .' pt of the origin, PDF\'s largest page side.',
                );
            }
        }
    }

    public static function fromResolvedAnchor(ResolvedAnchor $resolved, string $documentSha256): self
    {
        return new self(
            $documentSha256,
            $resolved->page,
            $resolved->occurrenceIndex,
            new MeasuredRect(
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
            MeasuredRect::fromArray($resolved['anchor_rect']),
            Rect::fromArray($resolved['rect']),
        );
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
