<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

use App\Domain\Preparation\Schema\FieldSchemaDocument;

/**
 * The one encoding a field value is hashed in.
 *
 * Same flags as {@see FieldSchemaDocument::JSON_FLAGS}, and
 * for the same reason: two encodings of the same value must not produce two digests, or a
 * recipient who reviewed a document would be told it changed when it did not. Slashes and
 * non-ASCII stay unescaped, and there is no `JSON_PRESERVE_ZERO_FRACTION`, so an integral
 * number is written `1` and never `1.0`.
 */
final class CanonicalValue
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** Canonical JSON for one value. */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    /** sha256 of the canonical JSON of one value. */
    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }
}
