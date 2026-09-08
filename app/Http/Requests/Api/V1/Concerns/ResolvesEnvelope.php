<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

use App\Domain\Integration\Native\EnvelopeService;
use App\Domain\Signing\Models\Envelope;

/**
 * Resolves `{envelope}` inside the credential's workspace, once per request.
 *
 * A trait rather than a base class because the envelope routes do not share one shape: some
 * take a body, one takes pagination, and inheriting from a single ancestor would drag
 * pagination rules onto a cancel call. What they do share is exactly this lookup — through
 * {@see EnvelopeService::find()}, which constrains by workspace *before* it compares the
 * public id — and writing it once is what stops it ever being written the other way round.
 */
trait ResolvesEnvelope
{
    private ?Envelope $resolvedEnvelope = null;

    public function envelope(): Envelope
    {
        return $this->resolvedEnvelope ??= app(EnvelopeService::class)
            ->find($this->workspace(), $this->routeValue('envelope'));
    }
}
