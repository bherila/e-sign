<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\Models\Document;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ShowDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Resources\Documents\DocumentResource;
use Illuminate\Http\JsonResponse;

/**
 * Upload and metadata for workspace documents.
 *
 * Both actions take a Form Request that has already resolved the workspace inside the
 * caller's memberships and run the workspace policy, so nothing here re-checks access and
 * nothing here can accidentally skip it.
 */
class DocumentController extends Controller
{
    /**
     * A rejected upload is still recorded, so this returns 422 with the document that was
     * created and the reasons it cannot be prepared. The bytes are retained for forensics
     * (see DocumentIntake); the sender gets the actionable messages preflight produced, not
     * a generic failure.
     */
    public function store(StoreDocumentRequest $request, DocumentIntake $intake): JsonResponse
    {
        $document = $intake->intake(
            $request->workspace(),
            $request->currentUser(),
            $request->file('file'),
            $request->title(),
        );

        $document->load(['revisions', 'workspace', 'uploader']);

        if ($document->status === DocumentStatus::PreflightFailed) {
            return response()->json([
                'message' => 'The PDF was not accepted for preparation.',
                'errors' => $this->rejectionMessages($document),
                'document' => (new DocumentResource($document))->toArray($request),
            ], 422);
        }

        return (new DocumentResource($document))
            ->response($request)
            ->setStatusCode(201);
    }

    public function show(ShowDocumentRequest $request): JsonResponse
    {
        $document = $request->document();
        $document->load(['revisions', 'workspace', 'uploader']);

        return (new DocumentResource($document))->response($request);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rejectionMessages(Document $document): array
    {
        /** @var array<string, mixed> $report */
        $report = $document->preflight_report ?? [];
        /** @var array<int, array<string, mixed>> $findings */
        $findings = $report['findings'] ?? [];

        return array_values(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['severity'] ?? null) === 'reject',
        ));
    }
}
