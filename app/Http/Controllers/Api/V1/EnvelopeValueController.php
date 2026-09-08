<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\EnvelopeValueReader;
use App\Http\Requests\Api\V1\ShowEnvelopeValuesRequest;
use App\Http\Resources\Api\V1\FieldValueResource;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/envelopes/{envelope}/values`.
 *
 * Every field of the schema, in schema order, with the value it currently holds. Readable at
 * any point in the lifecycle, not only after completion: an integration reconciling a
 * half-signed envelope needs to see what has been filled in so far, and refusing until
 * completion would push it into scraping the signing UI.
 *
 * Signatures are described rather than dumped unless `?include=images` asks otherwise. The
 * reasoning is on App\Domain\Integration\Native\FieldValueView, and it is not only about
 * size: an image returned by default ends up in the caller's request logs and error reports
 * whether or not anyone wanted it there.
 */
class EnvelopeValueController extends ApiController
{
    public function __construct(private readonly EnvelopeValueReader $values) {}

    public function index(ShowEnvelopeValuesRequest $request): JsonResponse
    {
        return $this->collection(
            $request,
            $this->values->read($request->envelope(), $request->includeImages()),
            FieldValueResource::class,
        );
    }
}
