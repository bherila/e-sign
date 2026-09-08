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
            self::AgreementDate, self::SigningDate => false,
            default => true,
        };
    }
}
