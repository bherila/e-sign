<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A webhook endpoint.
 *
 * There is no secret here, and there is no route that returns one after the fact. A secret
 * is shown exactly once — in the response to the call that created or rotated it — because
 * only a digest-free ciphertext is stored and there is no way to read it back. A lost secret
 * is rotated, never recovered.
 *
 * `enabled` is the operational fact a caller acts on. `consecutive_failures` is why it may
 * have stopped being true: an endpoint auto-disables after a run of failed deliveries, and
 * re-enabling it through `PATCH` clears the streak so the next auto-disable counts from the
 * moment the receiver was said to be fixed.
 *
 * `secret_previous_expires_at` is the end of a rotation's overlap window, during which both
 * the old and the new secret sign every attempt. Null means there is no overlap open.
 *
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;

        return [
            'id' => $endpoint->public_id,
            'url' => $endpoint->url,
            'description' => $endpoint->description,
            'event_filter' => $endpoint->event_filter,
            'enabled' => $endpoint->isEnabled(),
            'disabled_at' => $endpoint->disabled_at?->toIso8601String(),
            'disabled_reason' => $endpoint->disabled_reason,
            // Cast rather than passed through: the column is `NOT NULL DEFAULT 0`, and a
            // model that has just been inserted has not read the default back, so an
            // untouched attribute would be reported as null on the create response only.
            'consecutive_failures' => (int) $endpoint->consecutive_failures,
            'secret_previous_expires_at' => $endpoint->secret_previous_expires_at?->toIso8601String(),
            'created_at' => $endpoint->created_at?->toIso8601String(),
            'updated_at' => $endpoint->updated_at?->toIso8601String(),
        ];
    }
}
