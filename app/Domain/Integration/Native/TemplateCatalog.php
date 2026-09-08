<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\Models\TemplateVersion;

/**
 * The read side of templates, for an API caller.
 *
 * An integration picks a template and sends one of its **published** versions. Drafts are
 * not in this catalogue at all: a draft can still change, and offering a caller an id it
 * cannot send is an invitation to a confusing 422 later
 * ({@see TemplateVersion::snapshotForEnvelope()} refuses one). What a caller gets is
 * therefore a list of things it can actually use.
 *
 * Retired templates stay listed, with `retired_at` set. Retirement stops a template being
 * chosen for new work; hiding it would break an integration that stored an id and now cannot
 * tell "retired" from "deleted".
 *
 * Every query is constrained by the principal's workspace before an identifier is compared.
 */
final readonly class TemplateCatalog
{
    /**
     * @return Page<Template>
     *
     * @throws ApiException On a cursor this API did not issue.
     */
    public function list(Workspace $workspace, ?string $cursor, int $limit): Page
    {
        return Page::keyset(
            Template::query()
                ->inWorkspace($workspace)
                ->with(['versions' => static fn ($query) => $query->published()]),
            $cursor,
            $limit,
        );
    }

    /**
     * @throws ApiException
     */
    public function find(Workspace $workspace, string $publicId): Template
    {
        $template = Template::query()
            ->inWorkspace($workspace)
            ->where('public_id', $publicId)
            ->with(['versions' => static fn ($query) => $query->published(), 'aliases'])
            ->first();

        if (! $template instanceof Template) {
            throw ApiException::notFound('template');
        }

        return $template;
    }

    /**
     * The published versions of a template, oldest first.
     *
     * @return list<TemplateVersion>
     */
    public function publishedVersions(Template $template): array
    {
        $versions = $template->relationLoaded('versions')
            ? $template->versions
            : $template->versions()->published()->get();

        return array_values($versions
            ->filter(static fn (TemplateVersion $version): bool => $version->isPublished())
            ->sortBy(static fn (TemplateVersion $version): int => $version->version)
            ->values()
            ->all());
    }
}
