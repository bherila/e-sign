<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Domain\Preparation\Templates\Models\Template;
use App\Domain\Preparation\Templates\TemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\IndexTemplatesRequest;
use App\Http\Requests\Templates\ShowTemplateRequest;
use App\Http\Requests\Templates\StoreTemplateRequest;
use App\Http\Requests\Templates\UpdateTemplateRequest;
use App\Http\Resources\Templates\TemplateResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The template list and one template's own attributes.
 *
 * Every action takes a Form Request that has already resolved the workspace inside the
 * caller's memberships and run the workspace policy, so nothing here re-checks access and
 * nothing here can accidentally skip it.
 *
 * Nothing in this controller can change a version. `update` renames, re-describes, retires,
 * and restores — none of which reaches the snapshot an envelope copied, which is the whole
 * point of keeping content on versions instead of on the template.
 */
class TemplateController extends Controller
{
    public function __construct(private readonly TemplateService $templates) {}

    public function index(IndexTemplatesRequest $request): JsonResponse
    {
        $retired = $request->retiredFilter();

        $templates = Template::query()
            ->inWorkspace($request->workspace())
            ->when($retired === true, fn (Builder $query): Builder => $query->whereNotNull('retired_at'))
            ->when($retired === false, fn (Builder $query): Builder => $query->whereNull('retired_at'))
            ->with($this->relations())
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'templates' => TemplateResource::collection($templates)->toArray($request),
        ]);
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->createTemplate(
            $request->workspace(),
            $request->currentUser(),
            $request->templateName(),
            $request->description(),
        );

        return (new TemplateResource($this->loaded($template)))
            ->response($request)
            ->setStatusCode(201);
    }

    public function show(ShowTemplateRequest $request): JsonResponse
    {
        return (new TemplateResource($this->loaded($request->template())))->response($request);
    }

    /**
     * Rename, re-describe, retire, or restore.
     *
     * Retirement is applied after the attribute changes so a single request can rename and
     * retire in one go, and each writes its own audit event: "renamed" and "retired" are
     * different facts and a reader of the trail should not have to infer one from the other.
     */
    public function update(UpdateTemplateRequest $request): JsonResponse
    {
        $actor = $request->currentUser();
        $template = $this->templates->updateTemplate($request->template(), $actor, $request->changes());

        $retired = $request->retired();

        if ($retired !== null) {
            $template = $this->templates->setRetired($template, $actor, $retired);
        }

        return (new TemplateResource($this->loaded($template)))->response($request);
    }

    private function loaded(Template $template): Template
    {
        $template->load($this->relations());

        return $template;
    }

    /**
     * Eager loads for a template payload. `versions.documentRevision.document` is loaded
     * because the version summaries name the document each one snapshots, and without it
     * a list of templates would be a query per version (AGENTS.md: avoid N+1).
     *
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'workspace',
            'creator',
            'aliases',
            'versions.template',
            'versions.creator',
            'versions.documentRevision.document',
        ];
    }
}
