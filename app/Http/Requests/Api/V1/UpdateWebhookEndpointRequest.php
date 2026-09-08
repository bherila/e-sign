<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesWebhookEndpoint;

/**
 * `PATCH /api/v1/webhooks/endpoints/{endpoint}`.
 *
 * Only the keys that are present are applied, so a caller fixing a description cannot blank
 * an event filter by leaving it out. `event_filter: null` is therefore meaningfully
 * different from omitting it: null means "every event", omission means "leave the filter
 * alone".
 *
 * `enabled` re-enables an endpoint that auto-disabled after a run of failures, or pauses one
 * deliberately. Re-enabling clears the failure streak, so the next auto-disable counts from
 * the moment the receiver was said to be fixed.
 *
 * The secret is not here. Rotating it is a separate call with its own audit event, because a
 * receiver that moved to a new URL has not necessarily lost its secret.
 */
class UpdateWebhookEndpointRequest extends ApiRequest
{
    use ResolvesWebhookEndpoint;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'string', 'url', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'event_filter' => ['sometimes', 'nullable', 'array', 'min:1'],
            'event_filter.*' => ['required', 'string', 'max:128'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{url?: string, description?: string|null, event_filter?: list<string>|null}
     */
    public function changes(): array
    {
        $changes = [];
        $validated = $this->validated();

        if (array_key_exists('url', $validated)) {
            $changes['url'] = (string) $validated['url'];
        }

        if (array_key_exists('description', $validated)) {
            $changes['description'] = $validated['description'] === null
                ? null
                : (string) $validated['description'];
        }

        if (array_key_exists('event_filter', $validated)) {
            $changes['event_filter'] = is_array($validated['event_filter'])
                ? array_values(array_map(strval(...), $validated['event_filter']))
                : null;
        }

        return $changes;
    }

    public function enabled(): ?bool
    {
        $validated = $this->validated();

        return array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : null;
    }
}
