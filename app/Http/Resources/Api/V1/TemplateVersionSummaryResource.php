<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Preparation\Templates\Models\TemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published template version, as an integration needs to see it.
 *
 * Enough to choose one and to send it — its id, its number, when it was published, the
 * document digest it will copy, the schema digest, and the recipient roles that have to be
 * addressed — and no field geometry. The field set is large, it is the sender's design
 * rather than the caller's, and an integration that needs it has the workspace UI and the
 * canonical schema export.
 *
 * `recipients` is what a caller maps its own people onto when it creates an envelope: the
 * `id` here is the `recipients[].id` that `POST /envelopes` expects.
 *
 * No disk name and no object path, for the reason
 * App\Http\Resources\Documents\DocumentRevisionResource gives.
 *
 * @mixin TemplateVersion
 */
class TemplateVersionSummaryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TemplateVersion $version */
        $version = $this->resource;
        $revision = $version->documentRevision;

        return [
            'id' => $version->public_id,
            'version' => $version->version,
            'published_at' => $version->published_at?->toIso8601String(),
            'document' => [
                'id' => $revision?->document?->public_id,
                'sha256' => $revision?->sha256,
                'page_count' => $revision?->page_count,
            ],
            'field_schema_sha256' => $version->field_schema_sha256,
            'consent_policy_version' => $version->consent_policy_version,
            'recipients' => $version->recipients,
        ];
    }
}
