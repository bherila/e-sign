<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\Exceptions\SealKeyUnknownException;

/**
 * Every seal key version this deployment can verify with: the active one, plus every key it
 * has retired.
 *
 * ## The problem it solves
 *
 * Sealing uses exactly one key — the active one. Verification cannot. An artifact records the
 * `seal_key_id` that produced it and keeps that id forever, so after a rotation the deployment
 * holds documents sealed under ids that are no longer configured for signing. Resolving the
 * certificate from the *active* configuration would verify those against the wrong material,
 * or refuse them outright, which is the exact failure rotation is supposed to avoid.
 *
 * So verification resolves through this directory, keyed by the id the artifact itself
 * recorded. Nothing here reads, needs, or can return a private key: a retired key's private
 * half should already have been destroyed on whatever schedule the key policy sets, and the
 * certificate alone is what verification requires.
 *
 * ## Why a compact environment list, and not a directory convention
 *
 * `ESIGN_SEAL_RETIRED_KEYS` is a comma-separated list of `key-id|certificate-path[|chain-path]`
 * entries — the same compact shape `ESIGN_DELIVERY_ALLOWLIST` already uses in this config file.
 * A "drop the certificate in /srv/esign-keys/retired/ and name the file after the key id"
 * convention was the alternative and was rejected:
 *
 *  - **It is one line in one file on every profile.** On shared hosting there is no
 *    orchestration to mount a directory and no guarantee about where a writable path lives;
 *    the operator already edits `.env`, and this is one more line there. On Docker it is an
 *    environment entry on the worker service next to the paths it already sets.
 *  - **The key id is stated, not derived.** A filename convention makes renaming a file
 *    silently re-map artifacts to a different certificate. Here the binding between an id and
 *    a certificate is written down where a reviewer can read it.
 *  - **Presence on disk is not trust.** A certificate that happens to sit in the directory is
 *    not thereby accepted as a seal key version; it has to be listed.
 *  - **`config:cache` can export it.** The parsed result is plain arrays, so a cached config
 *    holds the same values a fresh one does — a directory scan at boot would not.
 *
 * The cost is that a rotation edits an environment variable instead of copying a file, which
 * is exactly the step `esign:seal:rotate` prints for the operator.
 */
final class SealCertificateDirectory
{
    /** @var array<string, SealCertificate> */
    private array $resolved = [];

    /** @var array<string, string> Key id to the reason it could not be resolved. */
    private array $problems = [];

    /**
     * @param  string  $activeKeyId  The id sealing currently uses; '' when nothing is configured.
     * @param  array{certificate_path: string, chain_path: string}  $active
     * @param  array<string, array{certificate_path: string, chain_path: string}>  $retired
     */
    public function __construct(
        private readonly string $activeKeyId,
        private readonly array $active,
        private readonly array $retired,
    ) {}

    /**
     * Build from the `esign.seal` configuration array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $retired = [];

        /** @var iterable<mixed> $configured */
        $configured = is_iterable($config['retired_keys'] ?? null) ? $config['retired_keys'] : [];

        foreach ($configured as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $keyId = self::string($entry, 'key_id');
            $certificatePath = self::string($entry, 'certificate_path');

            if ($keyId === '' || $certificatePath === '') {
                continue;
            }

            $retired[$keyId] = [
                'certificate_path' => $certificatePath,
                'chain_path' => self::string($entry, 'chain_path'),
            ];
        }

        $activeKeyId = self::string($config, 'key_id');

        // A retired entry that repeats the active id is dropped rather than merged: after a
        // rotation the operator appends the outgoing key to the list, and forgetting to change
        // ESIGN_SEAL_KEY_ID at the same time must not produce a deployment where the active id
        // silently resolves to the old certificate.
        unset($retired[$activeKeyId]);

        return new self(
            activeKeyId: $activeKeyId,
            active: [
                'certificate_path' => self::string($config, 'certificate_path'),
                'chain_path' => self::string($config, 'chain_path'),
            ],
            retired: $retired,
        );
    }

    /**
     * Parse the compact environment form into the configuration array shape.
     *
     * `ESIGN_SEAL_RETIRED_KEYS="seal-2026-a|/srv/esign-keys/2026-01/seal.crt|/srv/esign-keys/2026-01/chain.crt,seal-2025-a|/srv/esign-keys/2025-01/seal.crt"`
     *
     * Kept as plain arrays so `config:cache` can export the result.
     *
     * @return list<array{key_id: string, certificate_path: string, chain_path: string}>
     */
    public static function parseEnvironment(?string $raw): array
    {
        $entries = [];

        foreach (explode(',', (string) $raw) as $candidate) {
            $fields = array_map('trim', explode('|', $candidate));

            if (($fields[0] ?? '') === '' || ($fields[1] ?? '') === '') {
                continue;
            }

            $entries[] = [
                'key_id' => $fields[0],
                'certificate_path' => $fields[1],
                'chain_path' => $fields[2] ?? '',
            ];
        }

        return $entries;
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    /** @return list<string> Retired key ids, in the order they were configured. */
    public function retiredKeyIds(): array
    {
        return array_keys($this->retired);
    }

    /** @return list<string> Every key id this deployment claims to be able to verify. */
    public function keyIds(): array
    {
        $ids = $this->activeKeyId === '' ? [] : [$this->activeKeyId];

        return [...$ids, ...array_keys($this->retired)];
    }

    /** True when the id is configured here. It may still fail to load; see {@see problemWith()}. */
    public function knows(string $keyId): bool
    {
        return $keyId !== '' && ($keyId === $this->activeKeyId || isset($this->retired[$keyId]));
    }

    /**
     * The certificate that verifies artifacts sealed under this key id.
     *
     * Deliberately does **not** reject an expired certificate. A retired key's certificate is
     * usually expired, and the artifacts it sealed are none the worse for it: the certificate
     * was valid when it signed. Expiry matters for the active key, and
     * {@see SealMaterial} is the gate that enforces it.
     *
     * @throws SealKeyUnknownException When the id is not configured on this deployment.
     * @throws SealingException When it is configured but the certificate cannot be loaded.
     */
    public function certificateFor(string $keyId): SealCertificate
    {
        if (isset($this->resolved[$keyId])) {
            return $this->resolved[$keyId];
        }

        if (! $this->knows($keyId)) {
            throw new SealKeyUnknownException(
                'No certificate is configured for seal key id "'.$keyId.'". Artifacts sealed under it '
                .'cannot be verified by this deployment until it is listed in ESIGN_SEAL_RETIRED_KEYS.'
            );
        }

        $paths = $keyId === $this->activeKeyId ? $this->active : $this->retired[$keyId];

        try {
            return $this->resolved[$keyId] = SealCertificate::fromPath(
                keyId: $keyId,
                certificatePath: $paths['certificate_path'],
                chainPath: $paths['chain_path'],
            );
        } catch (SealingException $exception) {
            $this->problems[$keyId] = $exception->getMessage();

            throw $exception;
        }
    }

    /**
     * Why this key id cannot be resolved, or null when it can.
     *
     * The non-throwing form, for the health probe and the status command: both want to report
     * every key's state in one pass rather than stop at the first broken one.
     */
    public function problemWith(string $keyId): ?string
    {
        if (isset($this->resolved[$keyId])) {
            return null;
        }

        if (array_key_exists($keyId, $this->problems)) {
            return $this->problems[$keyId];
        }

        try {
            $this->certificateFor($keyId);

            return null;
        } catch (SealingException $exception) {
            return $this->problems[$keyId] = $exception->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
