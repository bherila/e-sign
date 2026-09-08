<?php

declare(strict_types=1);

namespace App\Http\Controllers\Editor;

use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Editor\ShowFieldEditorRequest;
use Illuminate\Contracts\View\View;

/**
 * The visual field editor page (Stage 2, issue #22).
 *
 * A Blade shell that mounts one React island, in the pattern
 * `resources/views/dashboard.blade.php` establishes: the server writes what the page needs
 * into `data-*` attributes and the client never guesses a URL. Everything here is a read;
 * writing goes back through the template version PATCH endpoint, which is the one place that
 * validates and stores a field set.
 *
 * ## Why the page geometry is embedded rather than fetched
 *
 * Field rectangles are checked against the *displayed* page size, which comes from the
 * document's preflight report (`docs/preparation/coordinate-space.md`). The same bytes are
 * available over `GET /workspaces/{workspace}/documents/{document}`, but embedding them here
 * means the editor cannot open with the PDF rendered and the geometry still in flight — a
 * state in which a drag would be converted against a page size nobody knows yet. One request,
 * one authorization path, and the numbers come from the same report the server validates
 * against.
 *
 * A page whose geometry preflight did not record is not invented: the editor is told the page
 * count it has geometry for and refuses to place a field beyond it, and the validator reports
 * `page_out_of_range` rather than silently accepting the field.
 */
class FieldEditorController extends Controller
{
    public function show(ShowFieldEditorRequest $request): View
    {
        $version = $request->templateVersion();
        $version->load(['template', 'documentRevision.document']);

        $revision = $version->documentRevision;
        $document = $revision?->document;

        if ($revision === null || ! $document instanceof Document) {
            // A version always references a review revision (TemplateService enforces it and
            // the foreign key is RESTRICT), so this is a corrupted row rather than a request
            // problem. Fail closed rather than render an editor with no document.
            abort(404);
        }

        $workspaceId = (string) $request->workspace()->public_id;
        $templateId = (string) $version->template?->public_id;
        $versionId = (string) $version->public_id;

        return view('editor', [
            'editor' => [
                'workspace' => [
                    'id' => $workspaceId,
                    'name' => (string) $request->workspace()->name,
                ],
                'template' => [
                    'id' => $templateId,
                    'name' => (string) $version->template?->name,
                ],
                'version' => [
                    'id' => $versionId,
                    'number' => $version->version,
                    'status' => $version->isPublished() ? 'published' : 'draft',
                    'published_at' => $version->published_at?->toIso8601String(),
                ],
                'document' => [
                    'id' => (string) $document->public_id,
                    'title' => (string) $document->title,
                    'revision_id' => (string) $revision->public_id,
                    'page_count' => $document->page_count,
                ],
                'urls' => [
                    'document_view' => route('documents.revisions.view', [
                        'workspace' => $workspaceId,
                        'document' => $document->public_id,
                        'revision' => $revision->public_id,
                    ]),
                    'version' => route('templates.versions.show', [
                        'workspace' => $workspaceId,
                        'template' => $templateId,
                        'version' => $versionId,
                    ]),
                    'schema' => route('templates.versions.schema', [
                        'workspace' => $workspaceId,
                        'template' => $templateId,
                        'version' => $versionId,
                    ]),
                    'save' => route('templates.versions.update', [
                        'workspace' => $workspaceId,
                        'template' => $templateId,
                        'version' => $versionId,
                    ]),
                    'template' => route('templates.show', [
                        'workspace' => $workspaceId,
                        'template' => $templateId,
                    ]),
                ],
                'csrf_token' => csrf_token(),
                'read_only' => $this->readOnly($request, $version),
                'read_only_reason' => $this->readOnlyReason($request, $version),
                'field_schema' => $version->fieldSchemaDocument()->toArray(),
                'field_schema_sha256' => (string) $version->field_schema_sha256,
                'pages' => $this->pageGeometry($document),
                'variables' => $this->prefillVariables(),
            ],
        ]);
    }

    private function readOnly(ShowFieldEditorRequest $request, TemplateVersion $version): bool
    {
        return $version->isPublished() || ! $request->canEdit();
    }

    private function readOnlyReason(ShowFieldEditorRequest $request, TemplateVersion $version): ?string
    {
        // Published wins when both apply: it is the reason that cannot be resolved by
        // changing anybody's role.
        if ($version->isPublished()) {
            return 'version_published';
        }

        return $request->canEdit() ? null : 'insufficient_role';
    }

    /**
     * The displayed geometry of every page, straight from the preflight report.
     *
     * `PageGeometry::toArray()` is the source; this narrows it to what the browser needs and
     * renames nothing, so a value that means CropBox in PHP means CropBox in TypeScript.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pageGeometry(Document $document): array
    {
        /** @var array<string, mixed> $report */
        $report = $document->preflight_report ?? [];
        $pages = $report['pages'] ?? [];

        if (! is_array($pages)) {
            return [];
        }

        $geometry = [];

        foreach ($pages as $page) {
            if (! is_array($page) || ! is_array($page['crop_box'] ?? null)) {
                continue;
            }

            $geometry[] = [
                'page' => (int) ($page['page'] ?? 0),
                'crop_box' => array_map(static fn (mixed $v): float => (float) $v, array_values($page['crop_box'])),
                'rotation' => (int) ($page['rotation'] ?? 0),
                'user_unit' => (float) ($page['user_unit'] ?? 1.0),
                'native_width' => (float) ($page['native_width'] ?? 0.0),
                'native_height' => (float) ($page['native_height'] ?? 0.0),
            ];
        }

        return $geometry;
    }

    /**
     * Prefill variables the editor offers and validates against.
     *
     * Empty by default, and that is the honest answer: a *template* has no sending context,
     * so it does not know which variables will resolve — which is exactly why
     * `TemplateService` does not run the resolvability check either
     * (docs/preparation/field-schema.md, "Checks that need context"). An empty list makes the
     * editor skip the check and say so, rather than run it against nothing and reject every
     * prefill. A deployment whose sending context has a fixed variable set declares it in
     * `config/esign.php` and gets the check back.
     *
     * @return array<int, string>
     */
    private function prefillVariables(): array
    {
        $variables = config('esign.preparation.prefill_variables', []);

        if (! is_array($variables)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $variables),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
