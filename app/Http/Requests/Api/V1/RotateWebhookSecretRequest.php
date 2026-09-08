<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesWebhookEndpoint;

/**
 * `POST /api/v1/webhooks/endpoints/{endpoint}/rotate-secret`.
 *
 * Rotation mints a successor and keeps the predecessor verifying for a grace window, so both
 * secrets sign every attempt inside it and a receiver can be reconfigured without dropping
 * an event. `grace_hours: 0` cuts over immediately, which is the right value when the
 * rotation is happening *because* a secret leaked.
 */
class RotateWebhookSecretRequest extends ApiRequest
{
    use ResolvesWebhookEndpoint;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'grace_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
        ];
    }

    public function graceHours(): ?int
    {
        $validated = $this->validated();

        return array_key_exists('grace_hours', $validated) ? (int) $validated['grace_hours'] : null;
    }
}
