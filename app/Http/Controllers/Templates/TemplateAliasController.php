<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Preparation\Templates\TemplateStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\DestroyTemplateAliasRequest;
use App\Http\Requests\Templates\StoreTemplateAliasRequest;
use App\Http\Resources\Templates\TemplateAliasResource;
use Illuminate\Http\JsonResponse;

/**
 * Foreign identifiers for a template.
 *
 * Both actions take `createTemplates`: an alias decides which agreement a provider id sends,
 * so adding or removing one is as consequential as publishing a version, and an auditor may
 * read the mapping without being able to change it.
 *
 * The alias travels in the body on both verbs, including DELETE. A provider template id is
 * somebody else's string; putting it in a path segment would mean percent-encoding it, and
 * an alias that round-trips through a URL is one encoding bug away from unmapping the wrong
 * template.
 */
class TemplateAliasController extends Controller
{
    use TranslatesTemplateFailures;

    public function __construct(private readonly TemplateService $templates) {}

    public function store(StoreTemplateAliasRequest $request): JsonResponse
    {
        try {
            $alias = $this->templates->addAlias(
                $request->template(),
                $request->currentUser(),
                $request->alias(),
                $request->source(),
            );
        } catch (TemplateStateException $e) {
            return $this->templateStateResponse($e);
        }

        return (new TemplateAliasResource($alias))
            ->response($request)
            ->setStatusCode(201);
    }

    public function destroy(DestroyTemplateAliasRequest $request): JsonResponse
    {
        try {
            $this->templates->removeAlias(
                $request->template(),
                $request->currentUser(),
                $request->alias(),
            );
        } catch (TemplateStateException $e) {
            return $this->templateStateResponse($e);
        }

        return response()->json(['alias' => $request->alias(), 'removed' => true]);
    }
}
