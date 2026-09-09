<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Templates\Models\TemplateVersion;
use App\Domain\Preparation\Templates\PublishedVersionIsImmutableException;
use App\Domain\Preparation\Templates\TemplateService;
use App\Domain\Preparation\Templates\TemplateStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\PublishTemplateVersionRequest;
use App\Http\Requests\Templates\ShowTemplateVersionRequest;
use App\Http\Requests\Templates\StoreTemplateVersionRequest;
use App\Http\Requests\Templates\UpdateTemplateVersionRequest;
use App\Http\Resources\Templates\TemplateVersionResource;
use Illuminate\Http\JsonResponse;

/**
 * Drafting, reading, editing, and publishing template versions.
 *
 * The lifecycle this surface exposes is exactly the one docs/HANDOFF.md section 6 asks for:
 *
 *   POST versions ─▶ draft (editable) ─▶ POST versions/{n}/publish ─▶ published (frozen)
 *                                                                        │
 *                        POST versions again ◀── an edit after publishing ┘
 *
 * There is no route that changes a published version, and `update` refuses one with a 409
 * rather than a 500: the model would refuse the write anyway, so the guarantee does not rest
 * on this controller remembering it.
 *
 * Field-schema failures come back as a 422 carrying every problem at once with JSON
 * Pointers — see TranslatesTemplateFailures for why the list appears in two shapes. Publishing
 * answers the same way, because it runs one more check the draft could not: it resolves every
 * anchor against the document revision the version snapshots (docs/preparation/anchors.md).
 */
class TemplateVersionController extends Controller
{
    use TranslatesTemplateFailures;

    public function __construct(private readonly TemplateService $templates) {}

    public function store(StoreTemplateVersionRequest $request): JsonResponse
    {
        try {
            $version = $this->templates->createDraftVersion(
                $request->template(),
                $request->currentUser(),
                $request->document(),
                $request->fieldSchema() ?? [],
                $request->consentPolicyVersion(),
                $request->renderSettings(),
            );
        } catch (InvalidFieldSchemaException $e) {
            return $this->invalidFieldSchemaResponse($e);
        } catch (TemplateStateException $e) {
            return $this->templateStateResponse($e);
        }

        return (new TemplateVersionResource($this->loaded($version)))
            ->response($request)
            ->setStatusCode(201);
    }

    public function show(ShowTemplateVersionRequest $request): JsonResponse
    {
        return (new TemplateVersionResource($this->loaded($request->templateVersion())))
            ->response($request);
    }

    public function update(UpdateTemplateVersionRequest $request): JsonResponse
    {
        try {
            $version = $this->templates->updateDraftVersion(
                $request->templateVersion(),
                $request->currentUser(),
                $request->fieldSchema(),
                $request->consentPolicyVersion(),
                $request->renderSettings(),
            );
        } catch (PublishedVersionIsImmutableException $e) {
            return $this->publishedVersionResponse($e);
        } catch (InvalidFieldSchemaException $e) {
            return $this->invalidFieldSchemaResponse($e);
        } catch (TemplateStateException $e) {
            return $this->templateStateResponse($e);
        }

        return (new TemplateVersionResource($this->loaded($version)))->response($request);
    }

    public function publish(PublishTemplateVersionRequest $request): JsonResponse
    {
        try {
            $version = $this->templates->publish($request->templateVersion(), $request->currentUser());
        } catch (InvalidFieldSchemaException $e) {
            // Publishing resolves every anchor against the revision the version snapshots, so a
            // field whose anchor text is missing from that document — or in it twice — is refused
            // here, with a pointer to the field, rather than discovered at send.
            return $this->invalidFieldSchemaResponse($e);
        } catch (TemplateStateException $e) {
            return $this->templateStateResponse($e);
        }

        return (new TemplateVersionResource($this->loaded($version)))->response($request);
    }

    private function loaded(TemplateVersion $version): TemplateVersion
    {
        $version->load(['template', 'creator', 'documentRevision.document']);

        return $version;
    }
}
