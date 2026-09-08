<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Native\WebhookEndpointService;
use App\Http\Requests\Api\V1\ApiPaginatedRequest;
use App\Http\Requests\Api\V1\RotateWebhookSecretRequest;
use App\Http\Requests\Api\V1\ShowWebhookEndpointRequest;
use App\Http\Requests\Api\V1\StoreWebhookEndpointRequest;
use App\Http\Requests\Api\V1\UpdateWebhookEndpointRequest;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook endpoint administration, under `webhooks:manage`.
 *
 * A separate scope from the envelope scopes, and deliberately not implied by them: an
 * integration that reads envelopes has no business redirecting a workspace's event stream,
 * and "nothing is implied" is the rule the scope vocabulary is built on
 * (docs/operations/service-credentials.md).
 *
 * ## The secret
 *
 * Returned exactly once, in the response to `POST` and to `rotate-secret`, alongside the
 * endpoint. Only ciphertext is stored and there is no route that reads one back, so a lost
 * secret is rotated rather than recovered. It is deliberately outside the endpoint resource,
 * so that the object a client caches and re-renders cannot accidentally carry it.
 *
 * ## `DELETE`
 *
 * Retires the endpoint rather than erasing it: `webhook_deliveries` references it, and a
 * delivery record naming an endpoint that no longer exists is not a record of anything. The
 * response is the endpoint with `enabled: false` — not a bare `204` that would imply the row
 * is gone.
 */
class WebhookEndpointController extends ApiController
{
    public function __construct(private readonly WebhookEndpointService $endpoints) {}

    public function index(ApiPaginatedRequest $request): JsonResponse
    {
        return $this->page(
            $request,
            $this->endpoints->list($request->workspace(), $request->cursor(), $request->limit()),
            WebhookEndpointResource::class,
        );
    }

    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        [$endpoint, $secret] = $this->endpoints->create(
            $request->workspace(),
            (string) $request->validated('url'),
            $request->validated('description') === null ? null : (string) $request->validated('description'),
            $request->eventFilter(),
            $request->credential(),
        );

        return $this->item(
            WebhookEndpointResource::make($endpoint)->resolve($request) + [
                // Shown once. There is no route that returns it again.
                'secret' => $secret,
            ],
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateWebhookEndpointRequest $request): JsonResponse
    {
        $endpoint = $this->endpoints->update(
            $request->endpoint(),
            $request->changes(),
            $request->enabled(),
            $request->credential(),
        );

        return $this->item(WebhookEndpointResource::make($endpoint)->resolve($request));
    }

    public function destroy(ShowWebhookEndpointRequest $request): JsonResponse
    {
        $endpoint = $this->endpoints->retire($request->endpoint(), $request->credential());

        return $this->item(WebhookEndpointResource::make($endpoint)->resolve($request));
    }

    public function rotateSecret(RotateWebhookSecretRequest $request): JsonResponse
    {
        $endpoint = $request->endpoint();

        $secret = $this->endpoints->rotateSecret(
            $endpoint,
            $request->graceHours(),
            $request->credential(),
        );

        return $this->item(
            WebhookEndpointResource::make($endpoint->refresh())->resolve($request) + [
                'secret' => $secret,
            ],
        );
    }
}
