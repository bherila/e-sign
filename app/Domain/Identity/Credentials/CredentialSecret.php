<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials;

/**
 * Minting and verification of a service credential secret.
 *
 * A plaintext secret is `<prefix>_<secret>`, for example
 * `esk_k3n9x2ab7q1z_p8w2r6m0v4t1y7c3q9j5h2n8b4d6f0s`. The prefix is the public half: it is
 * unique, it is stored in the clear, it is printed in tables and audit events, and it is what
 * authentication resolves a candidate row by. The second half is never stored.
 *
 * Why sha256 with a per-row salt rather than Laravel's `Hash`
 * ----------------------------------------------------------
 * A password hash buys cost against offline brute force of a secret a human chose. Nothing
 * here is human-chosen: `mint()` draws 43 characters from a 36-character alphabet
 * (~222 bits) out of `random_int()`, so an attacker holding the table has nothing to guess.
 * What a password hash would cost is real, though — verification finds exactly one row by
 * prefix, so a bcrypt comparison would add its full work factor to the latency of every
 * single API request, with no wide scan to amortise it over. bcrypt also silently truncates
 * input at 72 bytes and needs rehash-on-verify plumbing that a fixed-format random secret
 * has no use for.
 *
 * So: a fresh 128-bit salt per row, `hash('sha256', salt.':'.plaintext)`, and `hash_equals`
 * for the comparison. The salt is not there to slow anything down; it is there so that a
 * stolen table cannot be attacked with precomputation and so no two rows can be compared to
 * each other. If the threat model ever changes to include operator-chosen secrets, this
 * class is the only place that has to change.
 */
final readonly class CredentialSecret
{
    /**
     * Marks a string as one of this application's credentials, so a secret pasted into the
     * wrong field is recognisable in a support conversation without revealing anything.
     */
    public const PREFIX_MARKER = 'esk';

    private const PREFIX_CHARS = 12;

    private const SECRET_CHARS = 43;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private function __construct(
        public string $prefix,
        public string $plaintext,
        public string $salt,
        public string $hash,
    ) {}

    /**
     * A brand new secret. The plaintext exists only on this object and is shown to the
     * operator exactly once; nothing persists it.
     */
    public static function mint(): self
    {
        $prefix = self::newPrefix();
        $plaintext = $prefix.'_'.self::randomString(self::SECRET_CHARS);
        $salt = bin2hex(random_bytes(16));

        return new self($prefix, $plaintext, $salt, self::digest($salt, $plaintext));
    }

    public static function newPrefix(): string
    {
        return self::PREFIX_MARKER.'_'.self::randomString(self::PREFIX_CHARS);
    }

    /**
     * The public prefix of a presented secret, or null when the string is not shaped like
     * one of ours.
     *
     * Shape is checked before any database work so a garbage Authorization header costs a
     * regex rather than a query. The check is not a security boundary — the digest
     * comparison is — it only decides whether there is a prefix worth looking up.
     */
    public static function prefixFrom(string $presented): ?string
    {
        // `D` so `$` means end-of-subject and not "before an optional trailing newline".
        // Without it `esk_…_…\n` yields a prefix; harmless, because the digest is taken over
        // the untrimmed string and fails closed, but a shape check should mean its shape.
        $pattern = sprintf(
            '/^(%s_[a-z0-9]{%d})_[a-z0-9]{%d}$/D',
            preg_quote(self::PREFIX_MARKER, '/'),
            self::PREFIX_CHARS,
            self::SECRET_CHARS,
        );

        return preg_match($pattern, $presented, $matches) === 1 ? $matches[1] : null;
    }

    public static function digest(string $salt, string $presented): string
    {
        return hash('sha256', $salt.':'.$presented);
    }

    /**
     * The work a verification costs, performed against nothing, for the path where no row
     * was found.
     *
     * An unknown prefix and a wrong secret already return byte-identical answers; without
     * this they do not take the same time, because only the second reaches a digest and a
     * `hash_equals`. The result is discarded — the point is the cost, not the answer.
     */
    public static function burnVerification(string $presented): void
    {
        $salt = str_repeat('0', 32);

        hash_equals(self::digest($salt, $salt), self::digest($salt, $presented));
    }

    private static function randomString(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $value = '';

        for ($i = 0; $i < $length; $i++) {
            $value .= $alphabet[random_int(0, $max)];
        }

        return $value;
    }
}
