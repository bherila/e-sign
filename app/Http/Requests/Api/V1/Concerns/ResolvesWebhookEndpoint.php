<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Concerns;

use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Integration\Native\WebhookEndpointService;

/**
 * Resolves `{endpoint}` inside the credential's workspace, once per request.
 *
 * Composed into the endpoint routes the same way {@see ResolvesEnvelope} is composed into
 * the envelope routes, and for the same reason: the lookup is constrained by workspace
 * before the public id is compared, so another tenant's endpoint is a 404 and not a 403.
 */
trait ResolvesWebhookEndpoint
{
    private ?WebhookEndpoint $resolvedEndpoint = null;

    public function endpoint(): WebhookEndpoint
    {
        return $this->resolvedEndpoint ??= app(WebhookEndpointService::class)
            ->find($this->workspace(), $this->routeValue('endpoint'));
    }
}
