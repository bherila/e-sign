<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

use InvalidArgumentException;

/**
 * The one numeric rule of the native field schema.
 *
 * Coordinates are points and are stored as floats, but the *document* is the contract, so the
 * spelling of a number has to be pinned down or a round trip through the editor drifts. Two
 * rules, applied on import and on export:
 *
 * 1. At most three decimal places (0.001 pt is about 0.35 micron; the editor cannot express a
 *    meaningful drag at that resolution). Anything finer is rounded half away from zero, once,
 *    at construction, so every later encoding of the same value is identical.
 * 2. An integral value is written as a JSON integer (`60`), never `60.0`. PHP decodes `60` to
 *    int and `60.0` to float, so emitting the integer form is what makes
 *    `toArray(fromArray($x)) === $x` hold with strict comparison, and it matches what
 *    `JSON.stringify` produces in the TypeScript editor.
 *
 * @see FieldSchemaDocument::canonicalJson()
 */
final class CanonicalNumber
{
    /** Decimal places retained on a coordinate. */
    public const DECIMALS = 3;

    /**
     * Largest integer this schema admits: 2^53 - 1, the largest both implementations agree on.
     *
     * Every integer property — a page number, an occurrence index — is bounded by it, and for the
     * reason `AnchorPlacement::MAX_TOLERANCE` bounds a distance: a document must mean the same
     * thing in both projections. PHP's integers run to 2^63 - 1, and JavaScript's numbers stop
     * being exact at 2^53, so between the two a value is representable on one side and rounded on
     * the other — and the TypeScript importer would accept a document the PHP one refuses, which
     * an integration meets as a 422 after its own editor said the document was fine.
     *
     * `Number.MAX_SAFE_INTEGER` is the line because it is the largest integer JavaScript can hold
     * *and distinguish from its successor*. Below it both languages parse, compare and print the
     * same digits (docs/preparation/field-schema.md, "Why every number in this schema is
     * bounded").
     */
    public const MAX_INTEGER = 9_007_199_254_740_991;

    /**
     * Slack allowed when comparing a rounded coordinate against a page boundary.
     *
     * One unit in the last retained place: a rectangle that sits exactly on the page edge must
     * not be rejected because rounding moved it half a thousandth of a point outwards.
     */
    public const TOLERANCE = 0.001;

    /**
     * Whether a value is already canonical: at most {@see DECIMALS} decimal places.
     *
     * The importer refuses a finer value rather than rounding it, and the difference matters more
     * than it looks. Rounding is a *transformation*, and two implementations that both transform
     * can disagree about the result: `round(1.6484999999999999, 3)` is 1.648 in PHP and 1.649 in
     * the TypeScript editor, so the same submitted document would canonicalise to two different
     * byte strings and two different `field_schema_sha256` — the digest every attestation binds.
     * Refusing instead means no accepted value is ever transformed, so there is nothing for the
     * two to disagree about.
     *
     * Producers still round: the editor rounds what a drag produced, and resolution rounds what it
     * measured. That is safe precisely because it happens once, on one side, before the value
     * becomes part of a document — a producer's rounding is its own business, and what it sends is
     * then taken literally.
     */
    public static function isCanonical(float $value): bool
    {
        return is_finite($value) && round($value, self::DECIMALS) === $value;
    }

    /**
     * Round to the canonical precision, half away from zero.
     *
     * @throws InvalidArgumentException When the value is not finite. Callers that report rather
     *                                  than throw must check `is_finite()` first; the validator
     *                                  does, and emits `COORDINATE_NOT_FINITE`.
     */
    public static function round(float $value): float
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Coordinates must be finite numbers; got '.var_export($value, true).'.');
        }

        return round($value, self::DECIMALS);
    }

    /**
     * The canonical JSON representation of a coordinate: an integer when integral, otherwise
     * the rounded float.
     */
    public static function encode(float $value): int|float
    {
        $rounded = self::round($value);

        if ($rounded === floor($rounded) && abs($rounded) <= (float) PHP_INT_MAX) {
            return (int) $rounded;
        }

        return $rounded;
    }
}
