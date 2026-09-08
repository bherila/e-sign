<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\EnvelopeEventFeed;
use App\Http\Requests\Api\V1\ListEnvelopeEventsRequest;
use App\Http\Resources\Api\V1\EventResource;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/envelopes/{envelope}/events`.
 *
 * The pull half of the webhook feed, oldest first, with a cursor a caller can persist. An
 * integration that cannot accept inbound HTTP, or whose receiver was down for an hour, reads
 * the same events here in the same order rather than reconstructing them from state.
 *
 * Events are the same rows webhook deliveries are built from, so the `id` here is the event
 * id a receiver deduplicates on.
 */
class EnvelopeEventController extends ApiController
{
    public function __construct(private readonly EnvelopeEventFeed $events) {}

    public function index(ListEnvelopeEventsRequest $request): JsonResponse
    {
        return $this->page(
            $request,
            $this->events->forEnvelope(
                $request->workspace(),
                $request->envelope(),
                $request->cursor(),
                $request->limit(),
            ),
            EventResource::class,
        );
    }
}
