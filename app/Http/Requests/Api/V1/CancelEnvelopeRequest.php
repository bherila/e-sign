<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Signing\Envelopes\EnvelopeStateMachine;

/**
 * `POST /api/v1/envelopes/{envelope}/cancel`.
 *
 * The reason is optional and is recorded on the envelope and in the cancellation event. The
 * length limit is the state machine's own, so a reason that would be truncated by the column
 * is refused with a message naming the limit rather than silently shortened — half a reason
 * is a misleading reason.
 */
class CancelEnvelopeRequest extends EnvelopeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.EnvelopeStateMachine::MAX_REASON_LENGTH],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
