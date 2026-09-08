<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * `POST /api/v1/webhooks/endpoints`.
 *
 * The URL is checked here only for shape. Whether it is a destination this deployment will
 * actually call — not a link-local address, not something that resolves inside the network —
 * is decided by the outbound destination policy inside
 * App\Domain\Delivery\Webhooks\WebhookEndpointManager, at creation rather than at delivery
 * time, so an integrator finds out from this call instead of from a queue log. Reproducing
 * any of that policy in a validation rule would give two answers to the same question.
 *
 * `event_filter` omitted (or null) means every event. An empty array is refused, because it
 * describes an endpoint that wants nothing, which is a disabled endpoint spelled confusingly.
 */
class StoreWebhookEndpointRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:255'],
            'event_filter' => ['nullable', 'array', 'min:1'],
            'event_filter.*' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function eventFilter(): ?array
    {
        $filter = $this->validated('event_filter');

        return is_array($filter) ? array_values(array_map(strval(...), $filter)) : null;
    }
}
