<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Domain\Preparation\Schema\InvalidFieldSchemaException;
use App\Domain\Preparation\Schema\ValidationError;
use App\Domain\Preparation\Templates\PublishedVersionIsImmutableException;
use App\Domain\Preparation\Templates\TemplateStateException;
use Illuminate\Http\JsonResponse;

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
