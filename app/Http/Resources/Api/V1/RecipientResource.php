<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One party on an envelope.
 *
 * Two identifiers, and they are not interchangeable. `id` is this row's own public id, which
 * is what an event payload names. `schema_recipient_id` is the handle the field schema uses,
 * which is what fields are owned by and what `POST /envelopes` and `PATCH /envelopes/{id}`
 * address recipients with. Ownership is never resolved by signing order and never by email
 * (AGENTS.md), so both are published rather than one being derived from the other.
 *
 * `order_index` is the signing stage, counting from 1. In sequential mode a recipient stays
 * `pending` until the stage before theirs finishes.
 *
 * @mixin EnvelopeRecipient
 */
class RecipientResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EnvelopeRecipient $recipient */
        $recipient = $this->resource;

        return [
            'id' => $recipient->public_id,
            'schema_recipient_id' => $recipient->schema_recipient_id,
            'name' => $recipient->name,
            'email' => $recipient->email,
            'order_index' => $recipient->order_index,
            'state' => $recipient->state->value,
            'signed_at' => $recipient->signed_at?->toIso8601String(),
            'declined_at' => $recipient->declined_at?->toIso8601String(),
            'decline_reason' => $recipient->decline_reason,
        ];
    }
}
