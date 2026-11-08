<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Preparation\Geometry\DeclaredCoordinateConvention;
use App\Domain\Preparation\Schema\RecipientDesignation;
use App\Domain\Signing\Models\EnvelopeRecipient;

/**
 * The constants that define the `firma-compat-v1` acceptance profile.
 *
 * One class rather than scattered literals, because every one of these is a contract
 * decision recorded in `docs/compatibility/firma-capability-matrix.md` and a change to any
 * of them is a change to that document. In particular:
 *
 * - **{@see COORDINATES} is a declaration, not a guess.** The profile's `position` values
 *   are percentages of the displayed page, stated by the upstream schema (`minimum: 0`,
 *   `maximum: 100`, "percentage (0-100)") and corroborated by the consumer's live-tested
 *   comments. The upstream *examples* in the same document use PDF points and violate the
 *   schema they illustrate; disagreement D4 rules that the schema wins. Nothing in this
 *   module infers the unit from a number's magnitude (AGENTS.md, "Coordinates are never
 *   guessed"; `docs/preparation/coordinate-space.md`).
 * - **`designation` is the party's own** (issue #169). Upstream's `Signer|Approver|CC` maps
 *   one-to-one onto the field schema's `signer|approver|cc` ({@see designationOf()}), so
 *   what a payload says a party is, is what the state machine treats them as.
 * - **A cc recipient's `order` is their place among the cc recipients.** Upstream gives cc
 *   recipients "a separate sequence" that "never consume[s] a participant position"; here
 *   they are outside the signing order altogether, and {@see orderOf()} reports that
 *   separate sequence.
 * - **{@see DOWNLOAD_URL_TTL_MINUTES}** is the life of the app-issued download URL the
 *   facade returns where upstream returns a storage pre-sign. Fifteen minutes rather than
 *   upstream's hour: the URL is a capability, and the consumer follows it immediately.
 */
final class FirmaProfile
{
    /** The profile name, as it appears in the matrix, the scope, and the docs. */
    public const NAME = 'firma-compat-v1';

    /** Upstream `servers[0]` path, which the facade mounts verbatim. */
    public const BASE_PATH = 'functions/v1/signing-request-api';

    /** The scope that admits a credential to this surface. */
    public const SCOPE = 'compat:firma-v1';

    /** The declared coordinate convention of this profile. See the class docblock. */
    public const COORDINATES = DeclaredCoordinateConvention::PercentOfPageTopLeft;

    /** Upstream's `designation` for a party that does not say: a signer. */
    public const DESIGNATION = 'Signer';

    /**
     * Upstream's spelling of each designation.
     *
     * @var array<string, string>
     */
    public const DESIGNATIONS = [
        'signer' => 'Signer',
        'approver' => 'Approver',
        'cc' => 'CC',
    ];

    /** How long an issued `download_url` stays valid. */
    public const DOWNLOAD_URL_TTL_MINUTES = 15;

    /**
     * What a signature's `final_value` is when the caller did not ask for images.
     *
     * The recorded fixtures show this exact string where a signature was rendered as text,
     * and a PNG data URL where one was drawn. The facade emits the marker by default and the
     * data URL under `?include=images`, for the reason
     * App\Domain\Integration\Native\FieldValueView gives: an envelope carries one signature
     * image per signer, and returning them unasked puts them in the caller's request logs,
     * proxy caches, and error reports.
     */
    public const SIGNATURE_MARKER = 'Recipient Signature';

    /** Query value that asks for signature bytes instead of the marker. */
    public const INCLUDE_IMAGES = 'images';

    /**
     * The largest base64 `document` the facade accepts inline, in decoded bytes.
     *
     * Upstream documents ~4.5 MB for the inline field and 50 MB through `POST /documents`,
     * which is out of profile. The real ceiling is this deployment's own upload limit, so
     * that is what is enforced; this constant only bounds the base64 decode itself.
     */
    public const MAX_INLINE_DOCUMENT_BYTES = 4_718_592;

    /**
     * Upstream's ceiling for one document uploaded through `POST /documents` (50 MB), the
     * two-step route for a file too large for a JSON body (issue #182).
     */
    public const MAX_UPLOAD_BYTES = 52_428_800;

    /**
     * How long the URL a large upload is PUT to stays valid. The same fifteen minutes as every
     * download link this facade issues, and signed rather than advisory
     * ({@see DownloadUrlIssuer}).
     */
    public const UPLOAD_URL_TTL_MINUTES = 15;

    /**
     * How long an uploaded document's `document_id` can be used before it expires. Upstream calls
     * it a temporary upload and does not say for how long; a day is long enough for a client to
     * upload a set and build its request, and short enough that an id found in a log is stale.
     */
    public const UPLOAD_USABLE_HOURS = 24;

    /** Upstream's `designation` for a native one. */
    public static function designationOf(?RecipientDesignation $designation): string
    {
        return self::DESIGNATIONS[($designation ?? RecipientDesignation::Signer)->value];
    }

    /** The native designation for upstream's spelling, or null for one it does not have. */
    public static function designationFrom(string $designation): ?RecipientDesignation
    {
        $native = array_search($designation, self::DESIGNATIONS, true);

        return $native === false ? null : RecipientDesignation::from($native);
    }

    /**
     * The `order` the facade reports for a party.
     *
     * A signer's or an approver's stage, as stored. A cc recipient has no stage (they are
     * outside the signing order, stage 0), so theirs is their 1-based place among the
     * envelope's cc recipients, in the order they were created — which is the order of
     * upstream's separate cc sequence, because the facade creates them in that order.
     *
     * @param  iterable<EnvelopeRecipient>  $all  Every recipient of the same envelope.
     */
    public static function orderOf(EnvelopeRecipient $recipient, iterable $all): int
    {
        if ($recipient->designation !== RecipientDesignation::Cc) {
            return $recipient->order_index;
        }

        $position = 0;

        foreach ($all as $other) {
            if ($other->designation === RecipientDesignation::Cc && $other->getKey() <= $recipient->getKey()) {
                $position++;
            }
        }

        return max(1, $position);
    }
}
