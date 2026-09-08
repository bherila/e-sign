<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Sealing\Exceptions\SealMaterialInvalidException;
use App\Domain\Evidence\Sealing\Exceptions\SealMaterialUnavailableException;
use DateTimeImmutable;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The configured service seal certificate and private key, already checked.
 *
 * Constructing this object is the "reject absent, unusable, expired, or
 * mismatched configured signing material" gate: every failure mode is a typed
 * exception, so a caller cannot end up holding half-usable material. The
 * material is read here rather than handed to the PDF engine as a path, which
 * keeps key file access in application code and out of the engine's file
 * allowlist.
 */
final class SealMaterial
{
    /** Digest algorithms the CMS builder and this application both accept. */
    public const DIGEST_ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /**
     * @param  string  $keyId  Versioned identifier of this material.
     * @param  string  $certificatePem  PEM certificate of the service seal.
     * @param  SensitiveParameterValue  $privateKey  Decrypted PEM private key, wrapped.
     * @param  string  $chainPem  PEM bundle above the seal certificate, or ''.
     * @param  string  $digestAlgorithm  One of self::DIGEST_ALGORITHMS.
     * @param  string  $subject  Human-readable certificate subject, for evidence.
     * @param  string  $certificateFingerprint  Lowercase hex SHA-256 over the certificate DER.
     * @param  DateTimeImmutable  $notAfter  Certificate expiry, for expiry monitoring.
     */
    private function __construct(
        public readonly string $keyId,
        private readonly string $certificatePem,
        private readonly SensitiveParameterValue $privateKey,
        private readonly string $chainPem,
        public readonly string $digestAlgorithm,
        public readonly string $subject,
        public readonly string $certificateFingerprint,
        public readonly DateTimeImmutable $notAfter,
    ) {}

    /**
     * Build from the `esign.seal` configuration array.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws SealMaterialUnavailableException When a path is unset, missing, or unreadable.
     * @throws SealMaterialInvalidException When the material is unusable.
     */
    public static function fromConfig(array $config): self
    {
        $certificatePath = self::stringOption($config, 'certificate_path');
        $privateKeyPath = self::stringOption($config, 'private_key_path');
        $chainPath = self::stringOption($config, 'chain_path');
        $keyId = self::stringOption($config, 'key_id');
        $digestAlgorithm = strtolower(self::stringOption($config, 'digest_algorithm'));
        $passphrase = self::stringOption($config, 'private_key_passphrase');

        if ($certificatePath === '') {
            throw new SealMaterialUnavailableException(
                'No seal certificate is configured (ESIGN_SEAL_CERTIFICATE_PATH is empty).'
            );
        }

        if ($privateKeyPath === '') {
            throw new SealMaterialUnavailableException(
                'No seal private key is configured (ESIGN_SEAL_PRIVATE_KEY_PATH is empty).'
            );
        }

        if ($keyId === '') {
            throw new SealMaterialUnavailableException(
                'No seal key id is configured (ESIGN_SEAL_KEY_ID is empty); artifacts must record '.
                'which key material sealed them so a rotation stays verifiable.'
            );
        }

        if ($digestAlgorithm === '' || ! in_array($digestAlgorithm, self::DIGEST_ALGORITHMS, true)) {
            throw new SealMaterialInvalidException(
                'Unsupported seal digest algorithm "'.$digestAlgorithm.'"; expected one of '.
                implode(', ', self::DIGEST_ALGORITHMS).'.'
            );
        }

        return self::fromPem(
            keyId: $keyId,
            certificatePem: self::read($certificatePath, 'certificate'),
            privateKeyPem: self::read($privateKeyPath, 'private key'),
            chainPem: $chainPath === '' ? '' : self::read($chainPath, 'certificate chain'),
            digestAlgorithm: $digestAlgorithm,
            passphrase: $passphrase,
        );
    }

    /**
     * Build from PEM strings that are already in memory.
     *
     * @throws SealMaterialInvalidException When the material is unusable.
     */
    public static function fromPem(
        string $keyId,
        string $certificatePem,
        #[SensitiveParameter]
        string $privateKeyPem,
        string $chainPem = '',
        string $digestAlgorithm = 'sha256',
        #[SensitiveParameter]
        string $passphrase = '',
    ): self {
        // Shape first, so a file that is plainly not PEM never reaches OpenSSL.
        // Everything past this point is silenced deliberately: each failure
        // becomes a typed exception here, and the warning OpenSSL raises can
        // carry a filesystem path or key detail that must not reach a log.
        if (! str_contains($certificatePem, '-----BEGIN CERTIFICATE-----')) {
            throw new SealMaterialInvalidException('The seal certificate is not a PEM X.509 certificate.');
        }

        if (preg_match('/-----BEGIN (?:[A-Z0-9 ]+ )?PRIVATE KEY-----/', $privateKeyPem) !== 1) {
            throw new SealMaterialInvalidException('The seal private key is not a PEM private key.');
        }

        $certificate = @openssl_x509_read($certificatePem);
        if (! $certificate instanceof OpenSSLCertificate) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException('The seal certificate is not a readable PEM X.509 certificate.');
        }

        $privateKey = @openssl_pkey_get_private($privateKeyPem, $passphrase);
        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException(
                'The seal private key could not be loaded; it is malformed, of an unsupported type, or the '.
                'configured passphrase is wrong.'
            );
        }

        $parsed = @openssl_x509_parse($certificate);
        if ($parsed === false) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException('The seal certificate could not be parsed.');
        }

        $notBefore = self::timestamp($parsed, 'validFrom_time_t', 'notBefore');
        $notAfter = self::timestamp($parsed, 'validTo_time_t', 'notAfter');
        $now = new DateTimeImmutable('now');

        if ($now < $notBefore) {
            throw new SealMaterialInvalidException(
                'The seal certificate is not valid until '.$notBefore->format(DATE_ATOM).'.'
            );
        }

        if ($now > $notAfter) {
            throw new SealMaterialInvalidException(
                'The seal certificate expired on '.$notAfter->format(DATE_ATOM).'.'
            );
        }

        // Rejects the mismatched-material case: a key that loads and a
        // certificate that parses can still be unrelated.
        if (@openssl_x509_check_private_key($certificate, $privateKey) !== true) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException(
                'The configured seal private key does not match the configured seal certificate.'
            );
        }

        // Normalize to the canonical PEM the CMS builder expects, so a bundle
        // holding several certificates cannot smuggle a second signer in.
        $normalizedCertificate = '';
        if (@openssl_x509_export($certificate, $normalizedCertificate) !== true) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException('The seal certificate could not be re-encoded.');
        }

        if ($chainPem !== '' && ! str_contains($chainPem, '-----BEGIN CERTIFICATE-----')) {
            throw new SealMaterialInvalidException('The seal certificate chain holds no PEM certificate.');
        }

        // Decrypted once, here, so the passphrase never travels further into the
        // pipeline and the PDF engine is handed a key it can use directly.
        $decryptedKey = '';
        if (@openssl_pkey_export($privateKey, $decryptedKey) !== true) {
            self::clearOpenSslErrorQueue();

            throw new SealMaterialInvalidException('The seal private key could not be re-encoded.');
        }

        return new self(
            keyId: $keyId,
            certificatePem: $normalizedCertificate,
            privateKey: new SensitiveParameterValue($decryptedKey),
            chainPem: $chainPem,
            digestAlgorithm: $digestAlgorithm,
            subject: self::subjectOf($parsed),
            certificateFingerprint: self::fingerprintOf($normalizedCertificate),
            notAfter: $notAfter,
        );
    }

    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    /**
     * The decrypted, unencrypted private key PEM.
     *
     * Deliberately a method rather than a public property: the value must never
     * be logged, serialized into evidence, or rendered into a response.
     */
    public function privateKeyPem(): string
    {
        return $this->privateKey->getValue();
    }

    public function chainPem(): string
    {
        return $this->chainPem;
    }

    /**
     * Redact the material in var_dump() and print_r() output.
     *
     * This method is a courtesy, not the containment. `__debugInfo()` alone is
     * not enough: Symfony's VarDumper — which is what `dd()` and `dump()` use,
     * and what an exception page renders stack-frame locals with — *merges* this
     * array with the reflected property set rather than replacing it, so it
     * still descends into a raw string property. `serialize()` and
     * `var_export()` ignore this method outright.
     *
     * The containment is the SensitiveParameterValue wrapper around the key:
     * it redacts under VarDumper, var_export(), and var_dump(), and refuses to
     * serialize at all. SealMaterialTest pins every one of those vectors.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'keyId' => $this->keyId,
            'subject' => $this->subject,
            'certificateFingerprint' => $this->certificateFingerprint,
            'digestAlgorithm' => $this->digestAlgorithm,
            'notAfter' => $this->notAfter->format(DATE_ATOM),
            'certificatePem' => '<'.strlen($this->certificatePem).' bytes>',
            'privateKey' => '***',
            'chainPem' => $this->chainPem === '' ? '' : '<'.strlen($this->chainPem).' bytes>',
        ];
    }

    /**
     * SHA-256 over the certificate DER, as a validator reports it.
     *
     * Lets the publication gate check that the artifact was sealed by the
     * material the evidence record names, without re-parsing either side.
     */
    private static function fingerprintOf(string $certificatePem): string
    {
        $der = self::pemToDer($certificatePem);

        return $der === '' ? '' : hash('sha256', $der);
    }

    /**
     * The DER bytes of the first certificate in a PEM bundle, or ''.
     */
    public static function pemToDer(string $pem): string
    {
        $matches = [];
        if (preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $matches) !== 1) {
            return '';
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);

        return $der === false ? '' : $der;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function stringOption(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @throws SealMaterialUnavailableException
     */
    private static function read(string $path, string $what): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SealMaterialUnavailableException(
                'The configured seal '.$what.' is missing or unreadable at the configured path.'
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new SealMaterialUnavailableException(
                'The configured seal '.$what.' could not be read, or is empty.'
            );
        }

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $parsed
     *
     * @throws SealMaterialInvalidException
     */
    private static function timestamp(array $parsed, string $key, string $label): DateTimeImmutable
    {
        $value = $parsed[$key] ?? null;
        if (! is_int($value)) {
            throw new SealMaterialInvalidException(
                'The seal certificate has no readable '.$label.' validity bound.'
            );
        }

        return new DateTimeImmutable('@'.$value);
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
     * Drain the OpenSSL error queue after a deliberate failure.
     *
     * An error left queued is reported against the next unrelated OpenSSL call
     * in the same process, which turns one bad configuration into a confusing
     * failure somewhere else.
     */
    private static function clearOpenSslErrorQueue(): void
    {
        while (openssl_error_string() !== false) {
            // Discarded on purpose: the message can name a filesystem path.
        }
    }
}
