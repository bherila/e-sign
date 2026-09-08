<?php

declare(strict_types=1);

namespace App\Http\Resources\Documents;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\RevisionKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document, as a client sees it.
 *
 * Carries the identifiers, the digests, the preflight report, and the revision list.
 * It carries no disk name and no object path: see DocumentRevisionResource.
 *
 * The preflight report is included in full, including its warnings, because that is how the
 * sender is told what the system noticed about their file — including, when a review
 * revision was normalized, what the normalization cost.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * No `data` envelope. Stage 2 has no established response wrapper and inventing one
     * here would have to be undone when the native API under /api/v1 settles its own.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        $review = $document->reviewRevision();

        return [
            'id' => $document->public_id,
            'workspace_id' => $document->workspace?->public_id,
            'title' => $document->title,
            'status' => $document->status->value,
            'page_count' => $document->page_count,
            'uploaded_by' => $document->uploader === null ? null : [
                'name' => $document->uploader->name,
            ],
            'original' => [
                'sha256' => $document->original_sha256,
                'bytes' => $document->original_bytes,
                'mime' => $document->original_mime,
            ],
            'review_revision_id' => $review?->public_id,
            'revisions' => DocumentRevisionResource::collection(
                $document->revisions->sortBy(
                    static fn ($revision): int => $revision->kind === RevisionKind::Original ? 0 : 1,
                )->values(),
            ),
            'preflight' => $document->preflight_report,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
