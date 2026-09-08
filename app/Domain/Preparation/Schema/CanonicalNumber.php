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
     * Slack allowed when comparing a rounded coordinate against a page boundary.
     *
     * One unit in the last retained place: a rectangle that sits exactly on the page edge must
     * not be rejected because rounding moved it half a thousandth of a point outwards.
     */
    public const TOLERANCE = 0.001;

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
