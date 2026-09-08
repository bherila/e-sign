<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Delivery\Events\EnvelopeAudience;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `200` for `POST /signing-requests/{id}/cancel`.
 *
 * `CancelSigningRequestResponse` upstream, whose required members are `message`,
 * `signing_request_id` and `cancelled_on`, with `notify_signers` and `emails_sent` optional.
 * All five are emitted.
 *
 * `emails_sent` counts the parties who had already been written to, because those are the
 * only ones a withdrawal notice reaches: a later signer who was never invited is not told
 * that an agreement they never saw has been withdrawn. The count comes from
 * {@see EnvelopeAudience::invited()} — the same rule the mail scheduler applies — rather than
 * from the recipient list, so the number in the response and the messages that go out cannot
 * disagree. It is therefore zero for a draft nobody was written to about, which is the truth
 * and not a missing value.
 *
 * `notify_signers` is always true. A caller cannot switch the notice off — that is a `501`
 * before this response is built — so reporting anything else would be false.
 *
 * @mixin Envelope
 */
class CancelSigningRequestResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(Envelope $envelope)
    {
        parent::__construct($envelope);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Envelope $envelope */
        $envelope = $this->resource;

        return [
            'message' => 'Signing request cancelled.',
            'signing_request_id' => $envelope->public_id,
            'cancelled_on' => $envelope->cancelled_at?->toIso8601String(),
            'notify_signers' => true,
            'emails_sent' => $this->notified($envelope),
        ];
    }

    /**
     * How many parties the withdrawal notice goes to.
     *
     * See {@see EnvelopeAudience::invited()}: everyone who was written to, and nobody who
     * was not.
     */
    private function notified(Envelope $envelope): int
    {
        return count(EnvelopeAudience::invited($envelope));
    }
}
