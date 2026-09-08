<?php

declare(strict_types=1);

namespace App\Http\Resources\Compat\Firma;

use App\Domain\Delivery\Events\SigningRequestPayload;
use App\Domain\Integration\Firma\DownloadUrlIssuer;
use App\Domain\Integration\Firma\SigningRequestDownloads;
use App\Domain\Integration\Firma\SigningRequestSettings;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /signing-requests/{id}` — the polling response.
 *
 * The one shape the consumer reads on a loop, and the one with three separate things going on
 * at once:
 *
 * 1. **`status` is an object of booleans**, not a string. `sent`, `finished`, `cancelled`,
 *    `declined`, `expired`, and several can be true at once for a terminal state — a
 *    cancelled request that had been sent reports both, which a single enum cast could not.
 *    The projection is App\Domain\Delivery\Events\SigningRequestPayload::status(), the same
 *    one every `signing_request.*` webhook carries, so a polling receiver and a subscribing
 *    receiver cannot be told two different things.
 * 2. **`timestamps` uses the `_on` suffix.** The create response uses `_date` for the same
 *    instants. Both exist upstream and neither may be normalised into the other.
 * 3. **Five settings appear twice**, as booleans under `settings` and as deprecated `0`/`1`
 *    integers at the top level (disagreement D5). Both are emitted with the right type in
 *    each place.
 *
 * ## The download URLs, and `credit_cost`
 *
 * Upstream returns pre-signed storage URLs that "expire after 1 hour". This returns
 * app-issued URLs that expire in fifteen minutes and stream through the application, on every
 * driver, with nothing pre-signed (`docs/BLOB_STORAGE.md` rule 1;
 * {@see DownloadUrlIssuer}). The field names and the expiry semantics are preserved; the
 * mechanism is not, and that is recorded as an intentional difference.
 *
 * `credit_cost` is `null`. There is no credit system here, and the alternatives were both
 * worse: omitting the key breaks a consumer that reads `response.credit_cost`, and emitting a
 * number invents a billing fact. Null says "no cost is recorded", which is true.
 *
 * @mixin Envelope
 */
class SigningRequestDetailResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        Envelope $envelope,
        private readonly SigningRequestDownloads $downloads,
        private readonly SigningRequestSettings $settings,
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

        $revision = $envelope->documentRevision;
        $expiresAt = app(DownloadUrlIssuer::class)->expiresAt();

        return [
            'id' => $envelope->public_id,
            'name' => $envelope->title,
            // Upstream's own field for the description a template carried. An envelope copies
            // its source and does not copy the description, so there is nothing to report.
            'template_description' => null,
            'companies_workspaces_id' => $envelope->workspace?->public_id,
            // Never a storage URL. The reviewed revision — the bytes the parties were shown —
            // behind a short-lived app-issued link, on the same terms as every other download
            // this facade hands out.
            'document_url' => $this->downloads->documentUrl($envelope, $expiresAt),
            'document_url_expires_at' => $expiresAt->toIso8601String(),
            'document_page_count' => $revision?->page_count,
        ]
            + $this->settings->deprecatedIntegers($envelope)
            + [
                'settings' => $this->settings->for($envelope),
                'expiration_hours' => $envelope->expiration_hours,
                'expires_at' => $envelope->expires_at?->toIso8601String(),
                // See the class docblock: no credit system, and null is the honest answer.
                'credit_cost' => null,
                'status' => SigningRequestPayload::status($envelope),
                'timestamps' => SigningRequestPayload::timestamps($envelope),
                'certificate' => $this->certificate($envelope),
            ]
            + $this->downloads->detailUrls($envelope, $expiresAt);
    }

    /**
     * Upstream's `certificate` block, mapped onto the completion report.
     *
     * `generated` is true once the report has been published, which happens in the same
     * transaction that completes the request — so it is true exactly when the request is
     * finished and its evidence is retrievable, never before.
     *
     * It is a **report**, not an X.509 certificate and not a credential belonging to any
     * signer (AGENTS.md, "Honest language"). The member name is upstream's and is kept so a
     * consumer can read it; nothing user-facing describes it as a signer's certificate.
     *
     * @return array<string, mixed>
     */
    private function certificate(Envelope $envelope): array
    {
        $generatedOn = $this->downloads->reportGeneratedAt($envelope);

        return [
            'generated' => $generatedOn !== null,
            'generated_on' => $generatedOn?->toIso8601String(),
            // A finalization that failed leaves the request visibly failed rather than
            // finished, so a caller reads it from `status` and from the state machine's own
            // reason. This flag is true only for that state.
            'has_error' => $envelope->finalization_failure_reason !== null,
        ];
    }
}
