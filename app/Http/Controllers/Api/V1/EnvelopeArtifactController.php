<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\ApiException;
use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\ErrorCode;
use App\Domain\Integration\Native\LocatedArtifact;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use App\Http\Requests\Api\V1\EnvelopeRequest;
use App\Http\Resources\Api\V1\ArtifactResource;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The finished artifacts of a completed envelope, and their bytes.
 *
 * ## Two different refusals, and why they are different
 *
 * An envelope that has not completed answers `409 not_completed`. That is a statement about
 * the envelope: there is nothing to download because nobody has finished signing, or the
 * finalization failed and is visibly failed rather than quietly complete.
 *
 * A **completed** envelope with no artifacts answers `501 unsupported`. That is a statement
 * about this build: finalization has not been implemented yet, so the bytes this API
 * promises do not exist anywhere. Collapsing the two would tell an integrator that a
 * completed agreement is somehow not complete, and answering 200 with an empty list would be
 * the successful no-op AGENTS.md forbids. The seam is
 * {@see ArtifactLocator}; when finalization lands and binds a real locator, this branch
 * stops being reachable with no change here.
 *
 * ## Bytes
 *
 * Streamed through the application, never presigned, on any driver
 * (docs/BLOB_STORAGE.md rule 1). The content type is whatever the producer recorded, sent
 * with `nosniff`, and the response is `private, no-store`: an executed agreement must not sit
 * in a shared cache.
 */
class EnvelopeArtifactController extends ApiController
{
    public function __construct(private readonly ArtifactLocator $artifacts) {}

    public function index(EnvelopeRequest $request): JsonResponse
    {
        $envelope = $request->envelope();
        $found = $this->available($envelope);

        return response()->json([
            'data' => array_map(
                fn (LocatedArtifact $artifact): array => (new ArtifactResource($artifact, $envelope))
                    ->resolve($request),
                $found,
            ),
            'meta' => ['next_cursor' => null],
        ]);
    }

    public function download(EnvelopeRequest $request): StreamedResponse
    {
        $envelope = $request->envelope();
        $this->available($envelope);

        $artifact = $this->artifacts->find($envelope, (string) $request->route('artifact'));

        if (! $artifact instanceof LocatedArtifact) {
            throw ApiException::notFound('artifact');
        }

        $stream = $artifact->open();

        if (! is_resource($stream)) {
            // The locator says the bytes are there and the disk says otherwise. Fail loudly:
            // a truncated or empty 200 looks to a client like a valid short PDF.
            throw new RuntimeException(
                'Artifact '.$artifact->id.' of envelope '.$envelope->public_id.' is recorded but not readable.',
            );
        }

        return new StreamedResponse(
            function () use ($stream): void {
                // Straight from the storage stream to the output stream: a 200-page sealed
                // contract costs the same memory as a one-page one, on every driver.
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $artifact->contentType,
                'Content-Length' => (string) $artifact->bytes,
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $artifact->filename,
                    $artifact->filename,
                ),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
                // The digest recorded at finalization, so a caller can verify the transfer
                // without a second request.
                'X-Artifact-Sha256' => $artifact->sha256,
            ],
        );
    }

    /**
     * @return list<LocatedArtifact>
     *
     * @throws ApiException
     */
    private function available(Envelope $envelope): array
    {
        if ($envelope->state !== EnvelopeState::Completed) {
            throw ApiException::of(
                ErrorCode::NotCompleted,
                'This envelope is in state "'.$envelope->state->value.'". Artifacts exist only once it has completed.',
                ['state' => $envelope->state->value],
            );
        }

        $found = $this->artifacts->forEnvelope($envelope);

        if ($found === []) {
            throw ApiException::unsupported(
                'Artifact retrieval is not implemented in this build: the envelope has completed, but no finalized '
                .'artifact is available to return.',
                ['envelope_state' => $envelope->state->value],
            );
        }

        return $found;
    }
}
