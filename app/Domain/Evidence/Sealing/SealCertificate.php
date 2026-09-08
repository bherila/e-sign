<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Sealing\Exceptions\SealMaterialInvalidException;
use App\Domain\Evidence\Sealing\Exceptions\SealMaterialUnavailableException;
use DateTimeImmutable;
use OpenSSLCertificate;

/**
 * The public half of one seal key version: its certificate, and nothing else.
 *
 * ## Why this is not {@see SealMaterial}
 *
 * `SealMaterial` is the *signing* gate. Constructing it refuses material that is expired,
 * mismatched, or missing a private key, because sealing with any of those would be wrong.
 *
 * Verification is the opposite problem. An artifact sealed in 2026 under a certificate that
 * expired in 2027 is still a correctly sealed artifact — the certificate was valid when it
 * signed — and the deployment no longer holds, and must not need, the private key. A
 * verification path built on `SealMaterial` would therefore refuse to verify exactly the
 * documents rotation exists to protect. So this class:
 *
 *  - never touches a private key, and has no method that could return one;
 *  - never rejects a certificate for being expired. `notAfter` is reported; judging it is
 *    the caller's business, and for a retired key the answer is usually "expired, and that
 *    is fine".
 *
 * It still rejects material that is not a readable X.509 certificate at all, because that is
 * a configuration error rather than an age.
 */
final readonly class SealCertificate
{
    /**
     * @param  string  $keyId  The versioned key id artifacts record.
     * @param  string  $certificatePem  Normalized PEM of the seal certificate.
     * @param  string  $chainPem  PEM bundle above it, or ''.
     * @param  string  $subject  Human-readable subject, for status output and evidence.
     * @param  string  $fingerprint  Lowercase hex SHA-256 over the certificate DER.
     * @param  DateTimeImmutable  $notBefore  Start of the validity window.
     * @param  DateTimeImmutable  $notAfter  End of the validity window.
     */
    private function __construct(
        public string $keyId,
        public string $certificatePem,
        public string $chainPem,
        public string $subject,
        public string $fingerprint,
        public DateTimeImmutable $notBefore,
        public DateTimeImmutable $notAfter,
    ) {}

    /**
     * Read a certificate (and optional chain) from PEM already in memory.
     *
     * @throws SealMaterialInvalidException When the bytes are not a readable X.509 certificate.
     */
    public static function fromPem(string $keyId, string $certificatePem, string $chainPem = ''): self
    {
        if (trim($keyId) === '') {
            throw new SealMaterialInvalidException('A seal certificate must be registered under a non-empty key id.');
        }

        if (! str_contains($certificatePem, '-----BEGIN CERTIFICATE-----')) {
            throw new SealMaterialInvalidException(
                'The certificate registered for seal key id "'.$keyId.'" is not a PEM X.509 certificate.'
            );
        }

        // Silenced deliberately, as in SealMaterial: every failure becomes a typed exception,
        // and the OpenSSL warning can carry a filesystem path that must not reach a log.
        $certificate = @openssl_x509_read($certificatePem);
        if (! $certificate instanceof OpenSSLCertificate) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException(
                'The certificate registered for seal key id "'.$keyId.'" could not be read.'
            );
        }

        $parsed = @openssl_x509_parse($certificate);
        if ($parsed === false || ! is_int($parsed['validFrom_time_t'] ?? null) || ! is_int($parsed['validTo_time_t'] ?? null)) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException(
                'The certificate registered for seal key id "'.$keyId.'" has no readable validity window.'
            );
        }

        $normalized = '';
        if (@openssl_x509_export($certificate, $normalized) !== true) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException(
                'The certificate registered for seal key id "'.$keyId.'" could not be re-encoded.'
            );
        }

        $der = SealMaterial::pemToDer($normalized);

        return new self(
            keyId: trim($keyId),
            certificatePem: $normalized,
            chainPem: $chainPem,
            subject: self::subjectOf($parsed),
            fingerprint: $der === '' ? '' : hash('sha256', $der),
            notBefore: new DateTimeImmutable('@'.$parsed['validFrom_time_t']),
            notAfter: new DateTimeImmutable('@'.$parsed['validTo_time_t']),
        );
    }

    /**
     * Read a certificate from disk.
     *
     * @throws SealMaterialUnavailableException When the file is missing, unreadable, or empty.
     * @throws SealMaterialInvalidException When its contents are not a readable certificate.
     */
    public static function fromPath(string $keyId, string $certificatePath, string $chainPath = ''): self
    {
        return self::fromPem(
            keyId: $keyId,
            certificatePem: self::read($certificatePath, 'certificate', $keyId),
            chainPem: $chainPath === '' ? '' : self::read($chainPath, 'certificate chain', $keyId),
        );
    }

    /** The certificate's validity window has passed. Never an error here; a fact. */
    public function isExpired(?DateTimeImmutable $at = null): bool
    {
        return ($at ?? new DateTimeImmutable('now')) > $this->notAfter;
    }

    /** True when this certificate is the one an artifact row names. */
    public function matchesFingerprint(string $sha256): bool
    {
        return $sha256 !== '' && hash_equals($this->fingerprint, strtolower($sha256));
    }

    /**
     * @throws SealMaterialUnavailableException
     */
    private static function read(string $path, string $what, string $keyId): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new SealMaterialUnavailableException(
                'The seal '.$what.' for key id "'.$keyId.'" is missing or unreadable at the configured path.'
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new SealMaterialUnavailableException(
                'The seal '.$what.' for key id "'.$keyId.'" could not be read, or is empty.'
            );
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private static function subjectOf(array $parsed): string
    {
        $subject = $parsed['subject'] ?? null;
        if (! is_array($subject)) {
            return (string) ($parsed['name'] ?? '');
        }

        $parts = [];
        foreach ($subject as $key => $value) {
            $parts[] = $key.'='.(is_array($value) ? implode('+', array_map('strval', $value)) : (string) $value);
        }

        return implode(', ', $parts);
    }

    /**
     * Drain the OpenSSL error queue after a deliberate failure, so a queued message is not
     * reported against the next unrelated OpenSSL call in the same process.
     */
    private static function clearOpenSslErrorQueue(): void
    {
        while (openssl_error_string() !== false) {
            // Discarded on purpose: the message can name a filesystem path.
        }
    }
}
