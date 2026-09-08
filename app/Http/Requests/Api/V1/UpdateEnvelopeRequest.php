<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * `PATCH /api/v1/envelopes/{envelope}` — drafts only.
 *
 * Two things are correctable before an envelope goes out: what the sender prefilled, and who
 * the parties are. Neither is available afterwards, and that is the point — after send, an
 * invitation has been addressed and material content is on its way to being signed, so a
 * correction is a new envelope with renewed signatures (docs/ARCHITECTURE.md invariant 4).
 * The service refuses a non-draft with `409 illegal_transition`.
 *
 * `recipients[].id` is the **schema** recipient id (`buyer`, `seller`), not the recipient's
 * own public id. Fields find their owner by that handle, so it is the identifier that means
 * something in both the schema and the envelope.
 */
class UpdateEnvelopeRequest extends EnvelopeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'values' => ['sometimes', 'array'],

            'recipients' => ['sometimes', 'array'],
            'recipients.*.id' => ['required', 'string', 'max:128'],
            'recipients.*.name' => ['nullable', 'string', 'max:255'],
            'recipients.*.email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prefills(): array
    {
        $values = $this->validated('values');

        return is_array($values) ? $values : [];
    }

    /**
     * @return list<array{id: string, name?: string|null, email?: string|null}>
     */
    public function recipientCorrections(): array
    {
        $recipients = $this->validated('recipients');

        if (! is_array($recipients)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $recipient): array => [
                'id' => (string) $recipient['id'],
                'name' => isset($recipient['name']) ? (string) $recipient['name'] : null,
                'email' => isset($recipient['email']) ? (string) $recipient['email'] : null,
            ],
            $recipients,
        ));
    }
}
