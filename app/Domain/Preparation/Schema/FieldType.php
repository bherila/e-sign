<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * The field types implemented by native field schema 1.0.
 *
 * This list is a declared capability, not a suggestion: an unknown type is rejected on import
 * with `UNSUPPORTED_FIELD_TYPE` and never dropped from the document, because a silently ignored
 * field is a field a signer was never asked to complete (docs/HANDOFF.md section 7).
 *
 * Adding a type is an additive change: it bumps the schema minor version, is added here and to
 * `resources/schema/field-schema-1.0.json`'s successor, and is advertised in the capability
 * matrix. See docs/preparation/field-schema.md.
 */
enum FieldType: string
{
    /** A drawn, typed, or uploaded signature mark. */
    case Signature = 'signature';

    /** Initials, the same capture mechanism as a signature in a smaller box. */
    case Initials = 'initials';

    /** Free text entered by the recipient. */
    case Text = 'text';

    /** The recipient's printed name. */
    case Name = 'name';

    /** The recipient's organisation. */
    case Company = 'company';

    /** The recipient's job title. */
    case Title = 'title';

    /** The agreement's own effective date, set by the sender, identical for every recipient. */
    case AgreementDate = 'agreement_date';

    /** The date this recipient signed, captured by the service at acceptance time. */
    case SigningDate = 'signing_date';

    /** A single boolean tick box. */
    case Checkbox = 'checkbox';

    /** An approver's approval mark (schema 1.2). Approvers only. */
    case ApprovalSignature = 'approval_signature';

    /** An approver's approval tick (schema 1.2). Approvers only. */
    case ApprovalCheckmark = 'approval_checkmark';

    /** The date an approver approved, from the service's clock (schema 1.2). Approvers only. */
    case ApprovalDate = 'approval_date';

    /**
     * Declared type identifiers, in schema declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether the recipient supplies the value, as opposed to the service or the sender.
     *
     * Used by the editor to decide which fields a `read_only` flag can sensibly apply to; it
     * is not an authorization decision.
     */
    public function isRecipientEntered(): bool
    {
        return match ($this) {
            self::AgreementDate, self::SigningDate, self::ApprovalDate => false,
            default => true,
        };
    }

    /** The approval types, which only an approver may own (schema 1.2). */
    public function isApproval(): bool
    {
        return in_array($this, [self::ApprovalSignature, self::ApprovalCheckmark, self::ApprovalDate], true);
    }

    /** The marks a signer makes, which an approver may not own (schema 1.2). */
    public function isSignatureMark(): bool
    {
        return $this === self::Signature || $this === self::Initials;
    }

    /**
     * True for every type whose value is a captured mark: an adopted image or typed text,
     * re-encoded on the way in, described rather than echoed on the way out, and drawn as a
     * mark on the executed PDF. A signer's signature and initials, and an approver's approval
     * signature (issue #169).
     */
    public function capturesMark(): bool
    {
        return $this->isSignatureMark() || $this === self::ApprovalSignature;
    }

    /** The first schema minor that declares the approval types. */
    public const APPROVAL_SINCE_MINOR = 2;
}
