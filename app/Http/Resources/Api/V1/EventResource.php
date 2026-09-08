<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One outbox event, as the pull half of the same feed webhooks push.
 *
 * `id` is the event identity a receiver deduplicates on, and it is the same id the webhook
 * delivery carries, so an integration that reads both can reconcile them. `occurred_at` is
 * when the transition happened, not when this row was read.
 *
 * `payload` is the event's `data` object, exactly as it was recorded. It is not
 * re-serialised: the canonical body is what every delivery signs, and a payload rebuilt from
 * the current state of the envelope would describe the present rather than the event.
 *
 * @mixin OutboxEvent
 */
class EventResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OutboxEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->public_id,
            'event' => $event->event_name,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'payload' => $event->payload,
        ];
    }
}
