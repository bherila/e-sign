<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use App\Domain\Preparation\Geometry\NativeRect;

/**
 * A resolved anchor: the concrete rectangle that gets stored on the review revision.
 *
 * Once stored, the rectangle is what assembly uses. Anchors are never re-resolved **after** a
 * send, so a later text-extraction change cannot move a field a signer has already been shown.
 *
 * Before send is the opposite rule, and the distinction is the whole lifecycle: publishing a
 * template version and sending an envelope each resolve every anchored field again, receipt or
 * no receipt (`$defs/resolved_anchor` in `resources/schema/field-schema-1.1.json`). A receipt
 * records what was found; it is never permission to stop looking, because it binds a document, a
 * page and an occurrence but not the anchor text, the origin corner, or the offset — so honouring
 * one would let an edited request keep the answer to the question it used to ask.
 */
final readonly class ResolvedAnchor
{
    public function __construct(
        public Anchor $anchor,
        public int $page,
        public int $occurrenceIndex,
        public NativeRect $anchorRect,
        public NativeRect $resolvedRect,
        public string $matchedText,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'anchor_text' => $this->anchor->text,
            'page' => $this->page,
            'occurrence_index' => $this->occurrenceIndex,
            'anchor_rect' => $this->anchorRect->toArray(),
            'resolved_rect' => $this->resolvedRect->toArray(),
            'matched_text' => $this->matchedText,
        ];
    }
}
