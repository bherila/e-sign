<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Integration\Firma\SigningRequestFields;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `201` for `POST /signing-requests/create-and-send`.
 *
 * `status` is the string `"sent"` — upstream's enum on this route has one member, and by the
 * time this is rendered the invitations have been released, so there is nothing else it could
 * be. That is a *third* status representation alongside the create string and the detail
 * object, and all three coexist by design.
 *
 * ## `first_signer.signing_link`
 *
 * The one field where compatibility and honesty pull hardest in opposite directions, and the
 * resolution is worth reading.
 *
 * The consumer currently constructs `https://app.firma.dev/signing/{recipientId}` itself.
 * This service does not impersonate that site: its signing pages are served from this
 * application and visibly branded as this product (`docs/HANDOFF.md` §10). So the link is
 * ours, and it is the **stable resolver** `signing.legacy.show` — the same path shape, on
 * this host — rather than a freshly minted invitation.
 *
 * Not an invitation, for a specific reason. A recipient has at most one live invitation and
 * issuing another revokes the previous one, which is what makes "resend the link" mean
 * something. Minting one for this response would therefore either kill the link the
 * invitation email is about to carry, or be killed by it, depending on when the queue ran —
 * and either way this field would be a URL that goes nowhere. It would also put a second
 * live bearer credential for the same agreement into a second place.
 *
 * The resolver authorizes nothing by itself: a bare recipient identifier reaches a form, and
 * mailbox verification is what turns it into a session (`docs/HANDOFF.md` §8 refuses
 * "possession of the identifier is the authorization" outright and names this resolver as the
 * way to keep the URL shape without it).
 *
 * The consumer still has to stop building the URL and read this field — the host is
 * different, and a hardcoded third-party host is a link to somebody else's product.
 *
 * ## `credits_remaining`
 *
 * `null`. There is no credit ledger here, so any number would be invented; the key is kept so
 * a consumer reading it gets null instead of an undefined index.
 *
 * @mixin Envelope
 */
class CreateAndSendResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  string|null  $signingLink  Null only when the request has no recipients at all,
     *                                    which creation refuses.
     */
    public function __construct(
        Envelope $envelope,
        private readonly SigningRequestFields $fields,
        private readonly ?EnvelopeRecipient $firstSigner,
        private readonly ?string $signingLink,
    ) {
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
            'id' => $envelope->public_id,
            'name' => $envelope->title,
            'status' => 'sent',
            'first_signer' => $this->firstSigner === null ? null : [
                'id' => $this->firstSigner->public_id,
                'name' => $this->firstSigner->name,
                'email' => $this->firstSigner->email,
                'signing_link' => $this->signingLink,
            ],
            'recipients' => $this->recipients($envelope),
            'fields' => $this->fields->createResults($envelope),
            'sent_date' => $envelope->sent_at?->toIso8601String(),
            'expires_at' => $envelope->expires_at?->toIso8601String(),
            // See the class docblock: no credit ledger, so no number is invented.
            'credits_remaining' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recipients(Envelope $envelope): array
    {
        if (! $envelope->relationLoaded('recipients')) {
            $envelope->setRelation('recipients', $envelope->recipients()->get());
        }

        $rows = [];

        foreach ($envelope->getRelation('recipients') as $recipient) {
            /** @var EnvelopeRecipient $recipient */
            $rows[] = [
                'id' => $recipient->public_id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                'designation' => FirmaProfile::DESIGNATION,
                'order' => $recipient->order_index,
                'finished_on' => $recipient->signed_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
