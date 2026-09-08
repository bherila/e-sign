<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Integration\Native\TemplateCatalog;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A template on the native API.
 *
 * `current_version` is the published version a caller gets by picking the template, and it
 * is null until the first publish — a template with only drafts is deliberately not
 * sendable, and saying so is more useful than omitting the template from the list.
 *
 * `versions` carries every **published** version and appears on the detail response only.
 * A list of templates is for choosing one; the version history is what you read once you
 * have. Drafts never appear on either: they can still change, and offering an id that
 * `POST /envelopes` will refuse is a 422 waiting to happen.
 *
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    public static $wrap = null;

    private bool $includeVersions = false;

    /** The detail form: with the published version history. */
    public static function detailed(Template $template): self
    {
        $resource = new self($template);
        $resource->includeVersions = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Template $template */
        $template = $this->resource;

        $published = app(TemplateCatalog::class)->publishedVersions($template);
        $current = $this->currentPublishedVersion($template, $published);

        $payload = [
            'id' => $template->public_id,
            'name' => $template->name,
            'description' => $template->description,
            'retired_at' => $template->retired_at?->toIso8601String(),
            'current_version' => $current === null
                ? null
                : TemplateVersionSummaryResource::make($current)->resolve($request),
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];

        if ($this->includeVersions) {
            $payload['versions'] = array_map(
                static fn (TemplateVersion $version): array => TemplateVersionSummaryResource::make($version)
                    ->resolve($request),
                $published,
            );
        }

        return $payload;
    }

    /**
     * @param  list<TemplateVersion>  $published
     */
    private function currentPublishedVersion(Template $template, array $published): ?TemplateVersion
    {
        foreach ($published as $version) {
            if ($version->getKey() === $template->current_version_id) {
                return $version;
            }
        }

        return null;
    }
}
