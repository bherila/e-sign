<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

/**
 * The opaque `cursor` query parameter, and the only thing that may be inside it.
 *
 * A cursor is the autoincrement key of the last row of the previous page, base64url-encoded
 * behind a version tag. It is opaque on purpose: a client that decodes one and starts
 * arithmetic on it has coupled itself to a column, and the encoding gives this API room to
 * become a composite key later without breaking anyone who did not peek.
 *
 * Offsets are deliberately not offered. A page 40 that is computed by counting rows shifts
 * under a caller as soon as anything is inserted, which on an event feed is exactly when it
 * matters most; a keyset cursor cannot skip or repeat a row.
 *
 * Internal keys never leak: the encoded value is not a public identifier and appears only in
 * a cursor a client hands straight back.
 */
final class Cursor
{
    private const PREFIX = 'v1:';

    public static function encode(int $lastId): string
    {
        return rtrim(strtr(base64_encode(self::PREFIX.$lastId), '+/', '-_'), '=');
    }

    /**
     * @throws ApiException When the value is not a cursor this API issued.
     */
    public static function decode(string $cursor): int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || ! str_starts_with($decoded, self::PREFIX)) {
            throw self::invalid();
        }

        $id = substr($decoded, strlen(self::PREFIX));

        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            throw self::invalid();
        }

        return (int) $id;
    }

    private static function invalid(): ApiException
    {
        return ApiException::of(
            ErrorCode::InvalidCursor,
            'The cursor is not one this API issued. Pass back meta.next_cursor unchanged, or omit it for the first page.',
        );
    }
}
