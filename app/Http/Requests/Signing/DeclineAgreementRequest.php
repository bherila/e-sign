<?php

declare(strict_types=1);

namespace App\Http\Requests\Signing;

use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /sign/{envelope}/session/decline — one party refuses, and the envelope is declined.
 *
 * A decline is terminal for the whole agreement rather than for one signer
 * (docs/signing/state-machine.md), so it takes a deliberate confirmation as well as a
 * button press. `reason` stays optional: a party who will not say why is still declining,
 * and making the field mandatory would only produce a form full of full stops.
 *
 * The length ceiling is the state machine's own constant rather than a number retyped here,
 * so the form and the column cannot disagree about what fits.
 */
class DeclineAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decline_confirmed' => ['accepted'],
            'reason' => ['nullable', 'string', 'max:'.EnvelopeStateMachine::MAX_REASON_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decline_confirmed.accepted' => 'Please confirm you want to decline this agreement for everybody.',
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->string('reason')->trim()->value();

        return $reason === '' ? null : $reason;
    }
}
