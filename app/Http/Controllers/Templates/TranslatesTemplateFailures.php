<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Domain\Preparation\Anchoring\AnchorDocumentUnavailable;
use App\Domain\Preparation\Anchoring\AnchorResolutionDefect;
use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\ValidationError;
use App\Domain\Preparation\Templates\PublishedVersionIsImmutableException;
use App\Domain\Preparation\Templates\TemplateStateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Turns the Templates module's typed failures into HTTP answers.
 *
 * One place, so every template route answers the same way and a new route cannot
 * accidentally turn a refusal into a 500. The mapping:
 *
 * | Domain failure | Status | Why |
 * |---|---|---|
 * | invalid field schema | 422 | the caller sent a document that cannot be imported |
 * | `document_not_ready`, `document_in_another_workspace`, `document_has_no_review_revision` | 422 | the caller named an unusable document |
 * | `alias_already_taken` | 422 | the caller sent an alias somebody else holds |
 * | `alias_not_found` | 404 | there is nothing at the identifier the caller named |
 * | published version immutable, `template_retired`, `version_already_published` | 409 | the request is well formed and conflicts with current state |
 * | `anchor_document_unavailable` | 503 | the revision's bytes could not be read on publish; the same request may succeed on a retry |
 * | `anchor_resolution_defect` | 500 | anchor resolution contradicted its own request: a defect in this service, logged, never a problem with the document |
 *
 * A 409 rather than a 422 for the last group is the honest distinction: nothing about the
 * request is wrong, and re-sending it after the conflict is resolved (draft the next
 * version, restore the template) is exactly the right thing to do.
 *
 * ## Why the field-schema errors appear twice
 *
 * `errors.field_schema` is the shape every Laravel client and test helper already knows how
 * to read. `field_schema_errors` is the structured list the visual editor needs: one entry
 * per problem, each with an RFC 6901 JSON Pointer into the submitted document and a stable
 * code, so the editor can annotate every offending field in one pass instead of showing a
 * single "invalid document" message. Neither shape can carry the other's information, and
 * the codes are API surface — renaming one is a breaking change
 * (docs/preparation/field-schema.md).
 */
trait TranslatesTemplateFailures
{
    protected function invalidFieldSchemaResponse(InvalidFieldSchemaException $e): JsonResponse
    {
        return response()->json([
            'message' => 'The field schema document was rejected. Nothing was saved.',
            'errors' => [
                'field_schema' => array_map(
                    static fn (ValidationError $error): string => $error->describe(),
                    $e->errors(),
                ),
            ],
            'field_schema_errors' => $e->result->toArray(),
        ], 422);
    }

    protected function templateStateResponse(TemplateStateException $e): JsonResponse
    {
        return match ($e->reason) {
            'document_not_ready',
            'document_has_no_review_revision',
            'document_in_another_workspace' => $this->stateValidationResponse('document_id', $e),

            'alias_already_taken' => $this->stateValidationResponse('alias', $e),

            'alias_not_found' => response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 404),

            default => response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 409),
        };
    }

    /**
     * The document could not be read, so its anchors could not be resolved: a retryable server failure.
     *
     * The exception's message carries no storage handle; the cause went to the log where it was raised.
     */
    protected function anchorDocumentUnavailableResponse(AnchorDocumentUnavailable $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->code(),
        ], 503);
    }

    /**
     * Anchor resolution contradicted its own request. The detail goes to the log; the caller is
     * told only that nothing was published and nothing about their request needs to change.
     */
    protected function anchorResolutionDefectResponse(AnchorResolutionDefect $e): JsonResponse
    {
        Log::error('Anchor resolution produced a receipt that contradicts its request.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => 'This version\'s anchors could not be placed because of a defect on the service side. '
                .'Nothing was published, and nothing about the request needs to change.',
            'code' => $e->code(),
        ], 500);
    }

    protected function publishedVersionResponse(PublishedVersionIsImmutableException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'version_published',
            'template_version_id' => $e->templateVersionId,
        ], 409);
    }

    private function stateValidationResponse(string $field, TemplateStateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => [$field => [$e->getMessage()]],
            'code' => $e->reason,
        ], 422);
    }
}
