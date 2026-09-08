<?php

declare(strict_types=1);

namespace App\Http\Resources\Documents;

use App\Domain\Preparation\Documents\Models\DocumentRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One revision, as a client sees it.
 *
 * `disk` and `path` are absent by construction rather than by filtering: this array is
 * built key by key and neither key is ever added. A storage key in a response body is one
 * presigning bug away from a document that can be fetched without the workspace policy
 * running.
 *
 * @mixin DocumentRevision
 */
class DocumentRevisionResource extends JsonResource
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
        /** @var DocumentRevision $revision */
        $revision = $this->resource;

        return [
            'id' => $revision->public_id,
            'kind' => $revision->kind->value,
            'sha256' => $revision->sha256,
            'bytes' => $revision->bytes,
            'page_count' => $revision->page_count,
            'normalization' => $revision->normalization,
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }
}
