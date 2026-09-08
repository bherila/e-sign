<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Integration\Native\LocatedArtifact;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One finished artifact of a completed envelope.
 *
 * `download` is a path on this API, not a storage URL. Bytes stream through the application
 * so that authorization stays per-credential and per-record instead of per-URL-possession
 * (docs/BLOB_STORAGE.md rule 1); there is deliberately no presigned link on this API, on any
 * driver.
 *
 * `sha256` is the digest recorded and validated at finalization, so a caller can verify what
 * it downloaded without trusting the transfer.
 *
 * @mixin LocatedArtifact
 */
class ArtifactResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(LocatedArtifact $artifact, private readonly Envelope $envelope)
    {
        parent::__construct($artifact);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LocatedArtifact $artifact */
        $artifact = $this->resource;

        return [
            'id' => $artifact->id,
            'kind' => $artifact->kind,
            'filename' => $artifact->filename,
            'content_type' => $artifact->contentType,
            'sha256' => $artifact->sha256,
            'bytes' => $artifact->bytes,
            'created_at' => $artifact->createdAt?->toIso8601String(),
            'download' => route('api.v1.envelopes.artifacts.download', [
                'envelope' => $this->envelope->public_id,
                'artifact' => $artifact->id,
            ], absolute: false),
        ];
    }
}
