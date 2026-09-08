<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

/**
 * A submitted value was refused, with the reason as a stable code.
 *
 * One class with named constructors rather than five classes, because every one of these is
 * the same answer to the caller — "this value is not accepted, here is which field and
 * why" — and the surfaces above report them as one list of per-field errors, the way the
 * field-schema validator already reports its own.
 *
 * `reason` is API surface. The codes:
 *
 * | Code | Meaning |
 * |---|---|
 * | `unknown_field` | no field with this id exists in the envelope's copied schema |
 * | `field_not_owned` | the field exists but belongs to a different recipient |
 * | `field_read_only` | the schema marks the field read-only; only the sender may set it |
 * | `field_service_supplied` | the service derives this field from evidence it already holds |
 * | `field_requires_recipient` | only the owning recipient may supply this, never the sender |
 * | `field_frozen` | content is frozen and this field is not a signer-specific field of an unsigned recipient |
 * | `field_owner_signed` | the field's owner has already attested; their own fields are closed |
 * | `invalid_value` | the value is not of the shape the field's type accepts |
 */
final class FieldSubmissionRejected extends SigningException
{
    private function __construct(
        string $message,
        public readonly string $fieldId,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function unknownField(string $fieldId): self
    {
        return new self(
            'Field "'.$fieldId.'" is not in this envelope\'s field schema.',
            $fieldId,
            'unknown_field',
        );
    }

    public static function notOwned(string $fieldId, string $ownerId): self
    {
        return new self(
            'Field "'.$fieldId.'" belongs to recipient "'.$ownerId.'".',
            $fieldId,
            'field_not_owned',
        );
    }

    public static function readOnly(string $fieldId): self
    {
        return new self(
            'Field "'.$fieldId.'" is read-only and cannot be completed by its recipient.',
            $fieldId,
            'field_read_only',
        );
    }

    /**
     * A field the service fills in itself, from evidence it already holds.
     *
     * Today that is only `signing_date`, which is the owning recipient's attestation time.
     * Accepting a submitted value for it would let a client state when they signed.
     */
    public static function serviceSupplied(string $fieldId): self
    {
        return new self(
            'Field "'.$fieldId.'" is supplied by the service from the recipient\'s attestation and cannot be submitted.',
            $fieldId,
            'field_service_supplied',
        );
    }

    /**
     * A mark only the person themselves can make.
     *
     * A sender may prefill a printed name or a company, and routinely does. A sender may not
     * prefill a signature or a set of initials: that is not a prefill, it is signing on
     * somebody else's behalf.
     */
    public static function requiresRecipient(string $fieldId): self
    {
        return new self(
            'Field "'.$fieldId.'" can only be completed by its recipient; a sender cannot supply a signature mark.',
            $fieldId,
            'field_requires_recipient',
        );
    }

    public static function frozen(string $fieldId): self
    {
        return new self(
            'Field "'.$fieldId.'" cannot change: the envelope\'s content is frozen and this field is not a '
            .'signer-specific field of a recipient who has yet to sign.',
            $fieldId,
            'field_frozen',
        );
    }

    /**
     * The field's owner has already attested.
     *
     * A signer-specific field is not covered by the material digest — that is what makes it
     * signer-specific — so its owner's attestation cannot detect a later change to it. Once
     * they have signed, their own fields are as closed as the agreement's text, or the
     * printed name and title rendered beside their signature would be ones they never saw.
     */
    public static function ownerHasSigned(string $fieldId, string $ownerId): self
    {
        return new self(
            'Field "'.$fieldId.'" belongs to recipient "'.$ownerId.'", who has already attested; '
            .'their fields cannot change afterwards.',
            $fieldId,
            'field_owner_signed',
        );
    }

    public static function invalidValue(string $fieldId, string $detail): self
    {
        return new self(
            'Field "'.$fieldId.'" was given an unacceptable value: '.$detail,
            $fieldId,
            'invalid_value',
        );
    }

    public function code(): string
    {
        return $this->reason;
    }
}
