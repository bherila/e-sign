<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use Illuminate\Contracts\Encryption\Encrypter;
use SensitiveParameter;

/**
 * Digests of things that are short, guessable, or personal.
 *
 * An IP address is a 32-bit space, a six-digit code is a 20-bit space, and a user agent
 * string is drawn from a list any attacker already has. A plain SHA-256 of any of them is
 * reversible by enumeration, so a table of "hashes" would be a table of values with extra
 * steps. Keying the digest with a secret the database does not contain is what makes the
 * stored value actually opaque to somebody holding a dump.
 *
 * The key is derived from the application key rather than being the application key: a
 * separate purpose gets a separate derived key, so these digests do not share material with
 * cookie encryption, and rotating one does not silently change the meaning of the other.
 * Rotating `APP_KEY` does invalidate every digest here, which is correct — they are
 * corroboration for live sessions and short-lived codes, not records that have to outlive a
 * key rotation. Everything that must survive one (the attestation chain, the material
 * digest) uses an unkeyed hash of high-entropy input instead.
 */
final class KeyedDigest
{
    /** Domain separation, so two callers cannot produce colliding digests of the same input. */
    private const PURPOSE = 'esign.signing.keyed-digest.v1';

    public function __construct(private readonly Encrypter $encrypter) {}

    public function of(string $namespace, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $namespace."\0".$value, $this->key());
    }

    public function matches(string $namespace, #[SensitiveParameter] string $value, string $digest): bool
    {
        return hash_equals($digest, $this->of($namespace, $value));
    }

    private function key(): string
    {
        return hash_hmac('sha256', self::PURPOSE, $this->encrypter->getKey(), true);
    }
}
