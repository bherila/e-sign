<?php

declare(strict_types=1);

namespace App\Http\Resources\Templates;

use App\Domain\Preparation\Templates\Models\TemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A template version, as a client sees it.
 *
 * Carries the identifiers, the revision digest, the field set with its digest, the
 * recipients, the consent policy version, and the render settings — that is, the same facts
 * TemplateVersion::snapshotForEnvelope() gives the Signing module, so what a sender reviews
 * before publishing is what sending will copy.
 *
 * It carries no disk name and no object path, for the reason
 * App\Http\Resources\Documents\DocumentRevisionResource gives: a client that knows a storage
 * key is one presigning bug away from bypassing the workspace policy. The bytes are reached
 * through the document download routes, which stream through the application.
 *
 * `field_schema` is the **canonical** form, regenerated from the stored value rather than
 * echoed out of the column, so `field_schema_sha256` is always the digest of what is in this
 * response. See TemplateVersion for why the column itself cannot be trusted to be
 * byte-stable across database engines.
 *
 * @mixin TemplateVersion
 */
class TemplateVersionResource extends JsonResource
{
    /**
     * No `data` envelope, matching the document resources. Stage 2 has no established
     * response wrapper and inventing one here would have to be undone when the native API
     * under /api/v1 settles its own.
     */
    public static $wrap = null;

    private bool $includeFieldSchema = true;

    /**
     * The compact form used in a template's version list: everything except the field set,
     * which is the large part and which a client asks for one version at a time.
     *
     * @param  Collection<int, TemplateVersion>|iterable<int, TemplateVersion>  $versions
     * @return array<int, self>
     */
    public static function summaries(iterable $versions): array
    {
        $summaries = [];

        foreach ($versions as $version) {
            $resource = new self($version);
            $resource->includeFieldSchema = false;
            $summaries[] = $resource;
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TemplateVersion $version */
        $version = $this->resource;

        $revision = $version->documentRevision;

        $payload = [
            'id' => $version->public_id,
            'template_id' => $version->template?->public_id,
            'version' => $version->version,
            'status' => $version->isPublished() ? 'published' : 'draft',
            'published_at' => $version->published_at?->toIso8601String(),
            'document' => [
                'id' => $revision?->document?->public_id,
                'revision_id' => $revision?->public_id,
                'sha256' => $revision?->sha256,
                'page_count' => $revision?->page_count,
            ],
            'field_schema_sha256' => $version->field_schema_sha256,
            'recipients' => $version->recipients,
            'consent_policy_version' => $version->consent_policy_version,
            'render_settings' => $version->render_settings,
            'created_by' => $version->creator === null ? null : ['name' => $version->creator->name],
            'created_at' => $version->created_at?->toIso8601String(),
        ];

        if ($this->includeFieldSchema) {
            $payload['field_schema'] = $version->fieldSchemaDocument()->toArray();
        }

        return $payload;
    }
}
