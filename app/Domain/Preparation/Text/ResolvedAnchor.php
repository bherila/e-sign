<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use App\Domain\Preparation\Geometry\NativeRect;

/**
 * A resolved anchor: the concrete rectangle that gets stored on the review revision.
 *
 * Once stored, the rectangle is what assembly uses. Anchors are never re-resolved at
 * send or seal time, so a later text-extraction change cannot move an accepted field.
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
