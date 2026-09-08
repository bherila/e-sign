<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Integration\Firma\DownloadUrlIssuer;
use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Integration\Firma\SigningRequestDownloads;
use App\Domain\Integration\Firma\SigningRequestFields;
use App\Domain\Integration\Firma\SigningRequestSettings;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `201` for `POST /signing-requests`.
 *
 * A different shape from the polling response and deliberately so
 * (`docs/HANDOFF.md` section 10). Three differences are load-bearing:
 *
 * - **`status` is the string `"draft"`**, not an object of booleans. Upstream's enum on this
 *   route has exactly one member and creation does not send, so there is nothing else it
 *   could be.
 * - **The timestamps use the `_date` suffix**, where the detail response uses `_on`. Both
 *   spellings exist upstream for the same instants and neither may be normalised away.
 * - **The field rows spell the coordinates correctly.** `x_position` and `height`, because
 *   that is what upstream's create-response schema does; the two typos (`x_postion`,
 *   `heigh`) belong to the read shape only and are not propagated here (disagreement D9).
 *
 * `warnings` is the plural, array form — the singular `warning` belongs to PATCH, and
 * disagreement D7 says to preserve the difference rather than pick one. It is empty: the only
 * warnings upstream documents are email-format ones, and an address that does not validate is
 * a `400` here rather than an accepted request with a note attached.
 *
 * @mixin Envelope
 */
class SigningRequestCreateResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Envelope $envelope,
        private readonly SigningRequestFields $fields,
        private readonly SigningRequestDownloads $downloads,
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
        $expiresAt = app(DownloadUrlIssuer::class)->expiresAt();

        return [
            'id' => $envelope->public_id,
            'name' => $envelope->title,
            'status' => $envelope->state === EnvelopeState::Draft ? 'draft' : $envelope->state->value,
            'template_id' => $envelope->source_template_version_id,
            'document_url' => $this->downloads->documentUrl($envelope, $expiresAt),
            'page_count' => $envelope->documentRevision?->page_count,
            'expiration_hours' => $envelope->expiration_hours,
            'settings' => SigningRequestSettings::for($envelope),
            'recipients' => $this->recipients($envelope),
            'fields' => $this->fields->createResults($envelope),
            'created_date' => $envelope->created_at?->toIso8601String(),
            'updated_date' => $envelope->updated_at?->toIso8601String(),
            'sent_date' => $envelope->sent_at?->toIso8601String(),
            'finished_date' => $envelope->completed_at?->toIso8601String(),
            'cancelled_date' => $envelope->cancelled_at?->toIso8601String(),
            'warnings' => [],
        ];
    }

    /**
     * The parties, with the real ids a caller uses from here on.
     *
     * Upstream resolves the temporary ids (`temp_1`, `temp_2`…) a request used into permanent
     * ones in this response, and so does this: `id` is the recipient's own public id, and it
     * is the only handle a field or a signing session is ever bound to — never the signing
     * order and never the email address (AGENTS.md).
     *
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
            ];
        }

        return $rows;
    }
}
