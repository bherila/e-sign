<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use SensitiveParameter;

/**
 * How a guest credential is minted and how it is checked.
 *
 * One place, so an invitation token and a session token cannot end up with different
 * entropy or different comparison rules by accident.
 *
 * **Entropy.** 256 bits from `random_bytes`, base64url-encoded to 43 URL-safe characters.
 * Not `Str::random()`, which draws from the same CSPRNG but exists to produce readable
 * filler; a credential should say in its own construction that it is one.
 *
 * **Verifier.** A bare SHA-256, and deliberately not a password hash. bcrypt and argon2
 * exist to slow an attacker who is guessing a low-entropy human secret. There is nothing to
 * guess here: the input is full-entropy random, so a slow KDF buys no security and costs a
 * unique index — with a deterministic digest the lookup is one indexed read, and with a
 * salted one it is a scan that verifies every row in the table.
 *
 * **Comparison.** Lookup is by digest, so the database's own equality does the work and no
 * timing signal about the plaintext leaks. {@see matches()} exists for the paths that
 * already hold a row and want to re-check it; it uses `hash_equals`.
 *
 * Tokens are never logged, never echoed in an error, and never written to a column. The
 * only copy is the one handed to the caller that mails it, or the one in the browser's
 * cookie jar.
 */
final class SigningToken
{
    /** Bytes of randomness behind every credential this module issues. */
    public const ENTROPY_BYTES = 32;

    /** Length of the encoded form. Used to reject an obviously malformed path segment early. */
    public const ENCODED_LENGTH = 43;

    /**
     * Base64url, so the token is one path segment with no escaping and no `+`, `/`, or `=`.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }

    /** The verifier stored in the database. */
    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /** Constant-time re-check of a plaintext against a stored verifier. */
    public static function matches(#[SensitiveParameter] string $token, string $hash): bool
    {
        return hash_equals($hash, self::hash($token));
    }

    /**
     * Cheap shape check before a database round trip.
     *
     * Not a security boundary — a well-formed token is still worthless without a matching
     * row — but it keeps a path full of junk from becoming a query, and it is the reason a
     * scanner hammering `/sign/{ulid}/{garbage}` costs nothing.
     */
    public static function looksWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{'.self::ENCODED_LENGTH.'}$/', $token) === 1;
    }
}
