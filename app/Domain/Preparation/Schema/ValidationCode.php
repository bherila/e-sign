<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * Stable machine-readable reasons a field document is rejected.
 *
 * These codes are part of the API surface: the editor maps them to messages next to the
 * offending field, and the native API returns them verbatim. The TypeScript mirror in
 * `resources/js/schema/fieldSchema.ts` carries the same set, and a test asserts the two agree.
 * Renaming a code is a breaking change.
 */
enum ValidationCode: string
{
    /** A required property or whole section is absent: a partial import, never a default. */
    case MissingProperty = 'missing_property';

    /** A property the schema does not declare. Unknown properties are refused, not ignored. */
    case UnknownProperty = 'unknown_property';

    /** A property of the wrong JSON type (string where a number belongs, and so on). */
    case InvalidType = 'invalid_type';

    /** A string that does not match its declared format (identifier, variable name, length). */
    case InvalidFormat = 'invalid_format';

    /** An email address that is not usable as one. */
    case InvalidEmail = 'invalid_email';

    /** `recipients`, `signing_order`, or a signing stage is present but empty. */
    case EmptyCollection = 'empty_collection';

    /** `schema_version` is absent, malformed, or a version this importer does not implement. */
    case SchemaVersionUnsupported = 'schema_version_unsupported';

    /** `coordinate_space` declares a convention this version does not implement. */
    case UnsupportedCoordinateSpace = 'unsupported_coordinate_space';

    /** A field `type` outside the declared capability list. */
    case UnsupportedFieldType = 'unsupported_field_type';

    /** Two recipients or two fields share an id. */
    case DuplicateId = 'duplicate_id';

    /** Two fields share a template alias. */
    case DuplicateAlias = 'duplicate_alias';

    /** A `recipient_id`, or an id in `signing_order`, names a recipient that does not exist. */
    case UnknownRecipient = 'unknown_recipient';

    /** A declared recipient appears in no signing stage, so they would never be asked to sign. */
    case RecipientNotInSigningOrder = 'recipient_not_in_signing_order';

    /** A recipient appears in more than one signing stage, or twice in one. */
    case RecipientDuplicatedInSigningOrder = 'recipient_duplicated_in_signing_order';

    /** `page` is below 1, or beyond the page count of the document being validated against. */
    case PageOutOfRange = 'page_out_of_range';

    /** A coordinate is NaN or infinite. */
    case CoordinateNotFinite = 'coordinate_not_finite';

    /** `rect.x` or `rect.y` is negative, which is outside the page by construction. */
    case CoordinateNegative = 'coordinate_negative';

    /** `rect.width` or `rect.height` is zero or negative: not a placeable field. */
    case DimensionNotPositive = 'dimension_not_positive';

    /** The rectangle extends past the edge of the page it is placed on. */
    case RectOutOfPage = 'rect_out_of_page';

    /** A `prefill.variable` that the supplied variable set cannot resolve. */
    case UnresolvedPrefillVariable = 'unresolved_prefill_variable';

    /**
     * A coordinate with more precision than the canonical form keeps.
     *
     * Documents carry canonical numbers, so a value finer than a thousandth of a point is refused
     * rather than rounded. Rounding it would be a *transformation*, and two implementations that
     * transform can disagree about the result — which is a disagreement about the document's
     * digest, and that digest is what every attestation binds
     * (docs/preparation/anchors.md).
     */
    case CoordinateTooPrecise = 'coordinate_too_precise';

    /**
     * `anchor.required: false` on a field that is itself required.
     *
     * The compatibility option for an absent anchor is narrow on purpose: it says "this box may
     * legitimately not exist in this document", which can only be true of a box nobody has to
     * fill in. See docs/preparation/anchors.md.
     */
    case AnchorOptionalOnRequiredField = 'anchor_optional_on_required_field';

    /** A `cross_check` anchor resolved further than its tolerance from the declared rectangle. */
    case AnchorCrossCheckFailed = 'anchor_cross_check_failed';

    /** A required anchor's text does not occur anywhere in scope. */
    case AnchorNotFound = 'anchor_not_found';

    /** An `occurrence` of `sole` matched more than once. Ambiguity is never resolved by guessing. */
    case AnchorAmbiguous = 'anchor_ambiguous';

    /** An `occurrence` index beyond the number of matches in scope. */
    case AnchorOccurrenceOutOfRange = 'anchor_occurrence_out_of_range';

    /** The document's positioned text could not be extracted, so no anchor in it can be resolved. */
    case AnchorTextUnreadable = 'anchor_text_unreadable';

    /** An anchor resolved to a rectangle that does not fit on the page it was found on. */
    case AnchorResolvedOffPage = 'anchor_resolved_off_page';

    /**
     * A resolution receipt that the resolver could not have produced.
     *
     * Not a caller's error in the ordinary case: it means this service wrote a receipt that
     * contradicts the request it answers, which is a bug in resolution rather than in the
     * document. See `Anchoring\ReceiptVerifier`.
     */
    case AnchorReceiptInconsistent = 'anchor_receipt_inconsistent';

    /**
     * An anchor option that promises behaviour this deployment does not perform yet.
     *
     * A distinct code because the document is *not* wrong: it is a correct 1.1 document using an
     * option that is unavailable here, and a sender who reads "invalid" will go and change
     * something that was never the problem. See {@see AnchorResolutionGate}, which this code
     * disappears with.
     */
    case AnchorResolutionUnavailable = 'anchor_resolution_unavailable';
}
