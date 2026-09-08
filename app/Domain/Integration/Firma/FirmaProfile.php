<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma;

use App\Domain\Preparation\Geometry\DeclaredCoordinateConvention;

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
 * - **{@see DESIGNATION} is a constant.** Upstream's enum is `Signer|Approver|CC`; every
 *   party this product models signs, and emitting `Approver` for somebody nothing treats as
 *   an approver would put an authority in a payload that nothing enforces.
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

    /** Upstream's `designation` for every party we model. */
    public const DESIGNATION = 'Signer';

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
}
