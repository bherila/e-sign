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
 * The one field where compatibility and honesty pull hardest in opposite directions. The
 * consumer currently constructs `https://app.firma.dev/signing/{recipientId}` itself; this
 * service does not impersonate that site, and its signing UI is served from this application
 * and visibly branded as this product (`docs/HANDOFF.md` section 10). So the link is real and
 * it is ours, minted by App\Domain\Delivery\Events\SigningUrlMinter — the same minter the
 * invitation email uses, so the link in this response and the link in the signer's inbox are
 * the same session and not two.
 *
 * The consumer has to stop building the URL and start reading this field. That is recorded in
 * the capability matrix as an intentional difference, and it is not negotiable: a hardcoded
 * third-party host is a link to somebody else's product.
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
     * @param  string|null  $signingLink  Null only when guest signing is not bound in this
     *                                    deployment, which the error boundary would otherwise
     *                                    have turned into a 501 before the request was sent.
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
