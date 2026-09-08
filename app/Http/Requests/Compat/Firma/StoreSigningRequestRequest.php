<?php

declare(strict_types=1);

namespace App\Http\Requests\Compat\Firma;

/**
 * `POST /signing-requests`: the shape of the body, and only the shape.
 *
 * Two things are deliberately *not* decided here.
 *
 * **Which source was given.** `document` xor `template_id` is a rule about meaning rather
 * than shape, and the message a caller needs ("a request with both does not say which
 * agreement is meant") belongs beside the code that acts on it —
 * App\Domain\Integration\Firma\SigningRequestCreation.
 *
 * **Whether an option can be honoured.** `settings.hand_drawn_only: true` is a valid boolean
 * in the right place, and refusing it is a `501` about this deployment rather than a `422`
 * about the body (App\Domain\Integration\Firma\UnsupportedOptions). Validating it away here
 * would answer the wrong question with the wrong status.
 *
 * `fields` and `settings` are accepted as loose arrays on purpose. Their members mean
 * different things depending on the source — from a template a `fields[]` entry is a prefill,
 * from a document it is a placement — and a rule set that flattened that distinction would
 * either reject legitimate requests or wave through nonsense.
 */
class StoreSigningRequestRequest extends FirmaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_id' => ['nullable', 'string', 'max:191'],
            'document' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'expiration_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],

            'recipients' => ['sometimes', 'array', 'max:50'],
            'recipients.*' => ['array'],
            'recipients.*.id' => ['nullable', 'string', 'max:191'],
            'recipients.*.template_user_id' => ['nullable', 'string', 'max:191'],
            'recipients.*.first_name' => ['nullable', 'string', 'max:191'],
            'recipients.*.last_name' => ['nullable', 'string', 'max:191'],
            'recipients.*.name' => ['nullable', 'string', 'max:255'],
            'recipients.*.email' => ['required', 'string', 'email', 'max:320'],
            // Accepted so that the refusal is a 501 naming the designation rather than a 422
            // about an enum: an approver is a real thing to ask for and this build has none.
            'recipients.*.designation' => ['nullable', 'string', 'in:Signer,Approver,CC'],
            'recipients.*.order' => ['nullable', 'integer', 'min:1'],

            'fields' => ['sometimes', 'array', 'max:500'],
            'fields.*' => ['array'],

            'settings' => ['nullable', 'array'],

            // Present so the guard can name them. Each is refused with a 501 rather than
            // ignored (UnsupportedOptions).
            'anchor_tags' => ['nullable', 'array'],
            'reminders' => ['nullable', 'array'],
            'language' => ['nullable', 'string', 'max:32'],
            'completion_title' => ['nullable', 'string', 'max:255'],
            'completion_message' => ['nullable', 'string', 'max:2000'],
            'completion_redirect_url' => ['nullable', 'string', 'max:2048'],
            'completion_redirect_delay' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->validated();
    }
}
