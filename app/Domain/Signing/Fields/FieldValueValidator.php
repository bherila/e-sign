<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Preparation\Schema\FieldDefinition;
use App\Domain\Preparation\Schema\FieldType;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;

/**
 * The minimum a value must be before it is stored against a field.
 *
 * Deliberately shallow, and the boundary is worth stating: this rejects values that are the
 * wrong *kind of thing* for the field's declared type. It does not adopt a signature, decide
 * whether a canvas represents intent, re-encode an image, or apply a format rule. Signature
 * capture — typed and drawn adoption, controlled image rendering, the accessible
 * alternative — is issue #26, and docs/HANDOFF.md section 8 is explicit that a signature is
 * never recorded merely because a canvas is non-empty. What this class guarantees is that a
 * checkbox holds a boolean and a date holds a date, so the material digest is computed over
 * values of a known shape.
 *
 * Empty strings are refused rather than stored. A field with an empty value is a field that
 * was not completed, and storing one would let a required-field check pass on nothing.
 */
final class FieldValueValidator
{
    /** Generous, but bounded: a text field is not a document store. */
    public const MAX_TEXT_LENGTH = 10_000;

    /** A captured signature arrives as an opaque reference or data URL; #26 narrows this. */
    public const MAX_SIGNATURE_LENGTH = 2_000_000;

    /**
     * Return the value as it should be stored, or throw.
     *
     * @throws FieldSubmissionRejected
     */
    public static function normalize(FieldDefinition $field, mixed $value): mixed
    {
        return match ($field->type) {
            FieldType::Checkbox => self::boolean($field, $value),
            FieldType::AgreementDate, FieldType::SigningDate => self::date($field, $value),
            FieldType::Signature, FieldType::Initials => self::text($field, $value, self::MAX_SIGNATURE_LENGTH),
            FieldType::Text, FieldType::Name, FieldType::Company, FieldType::Title => self::text($field, $value, self::MAX_TEXT_LENGTH),
        };
    }

    private static function boolean(FieldDefinition $field, mixed $value): bool
    {
        if (! is_bool($value)) {
            throw FieldSubmissionRejected::invalidValue(
                $field->id,
                'a checkbox takes true or false, not '.get_debug_type($value).'.',
            );
        }

        return $value;
    }

    /**
     * An ISO 8601 calendar date, `YYYY-MM-DD`.
     *
     * No time and no zone: an agreement's effective date is a date in words, and attaching a
     * zone to it invents a precision the parties did not agree on. Acceptance *instants*
     * live on the attestation, in UTC, where they belong.
     */
    private static function date(FieldDefinition $field, mixed $value): string
    {
        if (! is_string($value)) {
            throw FieldSubmissionRejected::invalidValue(
                $field->id,
                'a date takes a YYYY-MM-DD string, not '.get_debug_type($value).'.',
            );
        }

        $parsed = date_parse_from_format('Y-m-d', $value);

        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0 || ! checkdate(
            (int) $parsed['month'],
            (int) $parsed['day'],
            (int) $parsed['year'],
        )) {
            throw FieldSubmissionRejected::invalidValue($field->id, '"'.$value.'" is not a YYYY-MM-DD date.');
        }

        return $value;
    }

    private static function text(FieldDefinition $field, mixed $value, int $maxLength): string
    {
        if (! is_string($value)) {
            throw FieldSubmissionRejected::invalidValue(
                $field->id,
                'this field takes a string, not '.get_debug_type($value).'.',
            );
        }

        if ($value === '') {
            throw FieldSubmissionRejected::invalidValue(
                $field->id,
                'an empty value is not a completed field; omit the field instead.',
            );
        }

        if (mb_strlen($value) > $maxLength) {
            throw FieldSubmissionRejected::invalidValue(
                $field->id,
                'the value is longer than the '.$maxLength.' character limit for this field type.',
            );
        }

        return $value;
    }
}
