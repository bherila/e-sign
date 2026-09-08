<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\TemplateCatalog;
use App\Http\Requests\Api\V1\ApiPaginatedRequest;
use App\Http\Requests\Api\V1\ApiRequest;
use App\Http\Resources\Api\V1\TemplateResource;
use Illuminate\Http\JsonResponse;

/**
 * Templates, read-only.
 *
 * There is no create or publish here on purpose. Authoring a template means placing fields
 * on a PDF, which is what the workspace UI and the visual editor are for; an API that
 * accepted a hand-written field set for a document it had never rendered would be an
 * excellent way to produce an agreement with a signature block off the page. `templates:read`
 * is what an integration needs — to find the version id it will send — and it is all this
 * family grants today. `templates:write` exists for when authoring lands.
 */
class TemplateController extends ApiController
{
    public function __construct(private readonly TemplateCatalog $catalog) {}

    public function index(ApiPaginatedRequest $request): JsonResponse
    {
        return $this->page(
            $request,
            $this->catalog->list($request->workspace(), $request->cursor(), $request->limit()),
            TemplateResource::class,
        );
    }

    public function show(ApiRequest $request): JsonResponse
    {
        $template = $this->catalog->find($request->workspace(), (string) $request->route('template'));

        return $this->item(TemplateResource::detailed($template)->resolve($request));
    }
}
