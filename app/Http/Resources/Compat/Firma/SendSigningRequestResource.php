<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `200` for `POST /signing-requests/{id}/send`.
 *
 * **This is the resolution of disagreement D8**, the sharpest contradiction in the pinned
 * document. The declared schema `SendSigningRequestResponse` is
 * `{success, message, sentTo, sentAt}` — camelCase, with nothing in its `required` list. The
 * `example` in the very same operation is
 * `{message, signing_request_id, recipients_notified, sent_date, expires_at}` — snake_case,
 * and disjoint from the schema except for `message`. There is no captured fixture for this
 * route, so nothing decides between them from observation.
 *
 * **The facade emits the union.** Every member of the schema and every member of the example,
 * with `message` shared. That is possible only because the two sets do not conflict: no key
 * appears in both with a different meaning or a different type, so satisfying one does not
 * break the other. A client written against the schema reads `success`/`sentTo`/`sentAt`; a
 * client written against the example reads `signing_request_id`/`recipients_notified`/
 * `sent_date`/`expires_at`; both work, and neither had to be guessed at.
 *
 * The alternative — pick one and be wrong for half the clients — was rejected because there
 * is no basis for the choice. Emitting both is recorded in the capability matrix as the
 * chosen behaviour, and if upstream ever resolves its own contradiction the extra members
 * become harmless surplus rather than a breaking change.
 *
 * `sentTo` carries the addresses this send **released** an invitation to, which for a
 * sequential request is the first stage and not everybody: `send()` releases one stage, and
 * reporting the whole list would tell a caller that a later signer had been written to when
 * they had not. It is read from the recipients' own state, which the transition sets, rather
 * than from `invited_at`, which is stamped later when the mail is actually enqueued — a
 * response cannot truthfully report the outcome of work the queue has not done yet, and a
 * caller asking "who did this call send to" means the release, not the SMTP handshake.
 *
 * @mixin Envelope
 */
class SendSigningRequestResource extends JsonResource
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
        $invited = $this->invited($envelope);

        return [
            // The declared schema's members.
            'success' => true,
            'message' => 'Signing request sent.',
            'sentTo' => $invited,
            'sentAt' => $envelope->sent_at?->toIso8601String(),

            // The operation example's members. See the class docblock for why both.
            'signing_request_id' => $envelope->public_id,
            'recipients_notified' => count($invited),
            'sent_date' => $envelope->sent_at?->toIso8601String(),
            'expires_at' => $envelope->expires_at?->toIso8601String(),
        ];
    }

    /**
     * The addresses this send released an invitation to. See the class docblock.
     *
     * @return list<string>
     */
    private function invited(Envelope $envelope): array
    {
        if (! $envelope->relationLoaded('recipients')) {
            $envelope->setRelation('recipients', $envelope->recipients()->get());
        }

        $addresses = [];

        foreach ($envelope->getRelation('recipients') as $recipient) {
            /** @var EnvelopeRecipient $recipient */
            // Anything but `pending` means the stage they are in has been released. A later
            // signer in a sequential request stays pending until their turn.
            if ($recipient->state !== RecipientState::Pending) {
                $addresses[] = $recipient->email;
            }
        }

        return $addresses;
    }
}
