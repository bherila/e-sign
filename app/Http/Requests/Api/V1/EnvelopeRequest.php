<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Integration\Native\EnvelopeService;
use App\Http\Requests\Api\V1\Concerns\ResolvesEnvelope;

/**
 * The base for every envelope-scoped route that does not paginate.
 *
 * See {@see ResolvesEnvelope} for why the lookup lives in a trait, and
 * {@see EnvelopeService::find()} for why it is a 404 rather
 * than a 403 when the id belongs to another tenant.
 */
class EnvelopeRequest extends ApiRequest
{
    use ResolvesEnvelope;
}
