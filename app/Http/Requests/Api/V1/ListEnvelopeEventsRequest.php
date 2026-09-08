<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesEnvelope;

/**
 * `GET /api/v1/envelopes/{envelope}/events`.
 *
 * Paginated, because an envelope's event history is unbounded in principle — reminders,
 * views, and recipient events accumulate — and a caller polling for completion should be
 * able to resume from where it stopped rather than re-read everything each time.
 */
class ListEnvelopeEventsRequest extends ApiPaginatedRequest
{
    use ResolvesEnvelope;
}
