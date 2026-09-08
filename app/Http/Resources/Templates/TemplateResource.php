<?php

declare(strict_types=1);

namespace App\Http\Resources\Templates;

use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A template, as a client sees it.
 *
 * `current_version` is the published version a sender gets when they pick this template, and
 * it is null until the first publish — a template with only drafts is deliberately not
 * sendable. `versions` is every version in number order, compactly: the field set is large
 * and is fetched one version at a time.
 *
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Template $template */
        $template = $this->resource;

        $current = $template->currentVersion();

        return [
            'id' => $template->public_id,
            'workspace_id' => $template->workspace?->public_id,
            'name' => $template->name,
            'description' => $template->description,
            'retired_at' => $template->retired_at?->toIso8601String(),
            'current_version' => $current === null ? null : [
                'id' => $current->public_id,
                'version' => $current->version,
                'published_at' => $current->published_at?->toIso8601String(),
                'field_schema_sha256' => $current->field_schema_sha256,
            ],
            'versions' => TemplateVersionResource::summaries(
                $template->versions->sortBy(
                    static fn (TemplateVersion $version): int => $version->version,
                )->values(),
            ),
            'aliases' => TemplateAliasResource::collection($template->aliases),
            'created_by' => $template->creator === null ? null : ['name' => $template->creator->name],
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
