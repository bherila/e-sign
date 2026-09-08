<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesWebhookEndpoint;

/**
 * The endpoint routes that carry no body: retire (`DELETE`) and any future read of one.
 */
class ShowWebhookEndpointRequest extends ApiRequest
{
    use ResolvesWebhookEndpoint;
}
