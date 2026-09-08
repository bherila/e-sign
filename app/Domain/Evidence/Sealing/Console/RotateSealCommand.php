<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Console;

use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\SealCertificate;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;
use App\Domain\Evidence\Sealing\SealMaterial;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Check proposed replacement seal material and print the environment change that adopts it.
 *
 * The operational half of a rotation (issue #29). It is deliberately **not** a command that
 * performs the rotation:
 *
 *  - **It writes no secrets.** It never edits `.env`, never copies a key file, and never
 *    prints a byte of the private key. A command that wrote key material would have to be
 *    given somewhere to write it, and the whole point of the deployment profiles in
 *    docs/operations/seal-key-management.md is that the key material's location and
 *    permissions are the operator's decision, made once, outside the application.
 *  - **It touches no artifacts.** A rotation never re-seals anything. Executed agreements
 *    keep the bytes people signed; the key id recorded on each one is what keeps them
 *    attributable afterwards.
 *  - **It is safe to run before the change.** Everything it does is a read: it opens the
 *    proposed certificate and key, proves they are usable and belong together, compares them
 *    against what is in force, and prints the diff an operator applies by hand. Running it on
 *    a live deployment changes nothing about how that deployment seals.
 *
 * What it refuses is as important as what it prints. Material that does not load, a
 * certificate that has expired, a key that does not match its certificate, a key id that
 * repeats the one in force, and a key id that was already retired are each a refusal with a
 * non-zero exit status, because every one of them produces artifacts that cannot be
 * attributed later.
 *
 * The audit event is the record that the rotation was prepared, with the outgoing and
 * incoming key ids and certificate fingerprints. It carries no filesystem path and no key
 * material.
 */
final class RotateSealCommand extends Command
{
    /** The action name written to `esign_audit_events`. */
    public const AUDIT_ACTION = 'seal.key.rotation_prepared';

    protected $signature = 'esign:seal:rotate
        {--key-id= : The new versioned key id. Must never have been used on this deployment}
        {--certificate= : Path to the new PEM X.509 seal certificate}
        {--private-key= : Path to the new PEM private key}
        {--chain= : Optional path to the PEM bundle above the new certificate, leaf first}
        {--digest= : CMS digest algorithm for the new material (default: the configured one)}
        {--passphrase-env= : Name of an environment variable holding the new key passphrase}
        {--dry-run : Check and print without recording an audit event}';

    protected $description = 'Check new service seal material and print the environment change that rotates to it';

    public function handle(Repository $config, SealCertificateDirectory $directory, AuditRecorder $audit): int
    {
        $keyId = $this->trimmedOption('key-id');
        $certificatePath = $this->trimmedOption('certificate');
        $privateKeyPath = $this->trimmedOption('private-key');

        if ($keyId === '' || $certificatePath === '' || $privateKeyPath === '') {
            $this->error('--key-id, --certificate, and --private-key are all required.');

            return self::INVALID;
        }

        $outgoingKeyId = (string) $config->get('esign.seal.key_id', '');

        if ($keyId === $outgoingKeyId) {
            $this->error(
                'The new key id is the one already in force. A rotation must use a new id: the id is how '
                .'an artifact says which material sealed it, and reusing it makes artifacts from before '
                .'and after the rotation indistinguishable.'
            );

            return self::INVALID;
        }

        if (in_array($keyId, $directory->retiredKeyIds(), true)) {
            $this->error(
                'Key id "'.$keyId.'" is already listed as a retired key on this deployment. Rotating back '
                .'onto a retired id would make its artifacts ambiguous. Choose a new id.'
            );

            return self::INVALID;
        }

        $incoming = $this->loadIncoming($keyId, $certificatePath, $privateKeyPath);

        if ($incoming === null) {
            return self::FAILURE;
        }

        $outgoing = $this->outgoingCertificate($directory, $outgoingKeyId);

        if ($outgoing instanceof SealCertificate && $outgoing->fingerprint === $incoming->certificateFingerprint) {
            $this->error(
                'The proposed certificate is the one already in force (same SHA-256). This is a new key id '
                .'over the same material, not a rotation.'
            );

            return self::INVALID;
        }

        $this->report($outgoingKeyId, $outgoing, $incoming);
        $this->printEnvironmentChange($config, $outgoingKeyId, $keyId, $certificatePath, $privateKeyPath);

        if ($this->option('dry-run') === true) {
            $this->comment('--dry-run: no audit event was recorded.');

            return self::SUCCESS;
        }

        $audit->record(
            actor: AuditActor::console('esign:seal:rotate'),
            action: self::AUDIT_ACTION,
            payload: [
                // Identifiers and digests only. No filesystem path, no subject of a key file,
                // and nothing derived from the private key: this row is read operationally and
                // a path is exactly the detail docs/security/review-2026-09.md keeps out of it.
                'outgoing_key_id' => $outgoingKeyId,
                'outgoing_certificate_sha256' => $outgoing?->fingerprint ?? '',
                'incoming_key_id' => $incoming->keyId,
                'incoming_certificate_sha256' => $incoming->certificateFingerprint,
                'incoming_not_after' => $incoming->notAfter->format(DATE_ATOM),
                'digest_algorithm' => $incoming->digestAlgorithm,
            ],
        );

        $this->info('Recorded the prepared rotation in the audit trail. Nothing has changed yet.');

        return self::SUCCESS;
    }

    /**
     * Open the proposed material, which *is* the check.
     *
     * `SealMaterial::fromConfig()` refuses absent, unreadable, malformed, not-yet-valid,
     * expired, and mismatched material, and an unsupported digest algorithm, each as a typed
     * exception. There is nothing left for this command to re-check by hand.
     */
    private function loadIncoming(string $keyId, string $certificatePath, string $privateKeyPath): ?SealMaterial
    {
        try {
            return SealMaterial::fromConfig([
                'key_id' => $keyId,
                'certificate_path' => $certificatePath,
                'private_key_path' => $privateKeyPath,
                'chain_path' => $this->trimmedOption('chain'),
                'digest_algorithm' => $this->trimmedOption('digest') === ''
                    ? (string) config('esign.seal.digest_algorithm', 'sha256')
                    : $this->trimmedOption('digest'),
                'private_key_passphrase' => $this->passphrase(),
            ]);
        } catch (SealingException $exception) {
            $this->error('The proposed seal material is not usable: '.$exception->getMessage());
            $this->line('');
            $this->line('Nothing was changed. Fix the material and run this again.');

            return null;
        }
    }

    /**
     * The passphrase for the new key, read from an environment variable rather than an option.
     *
     * A `--passphrase=` option would put the secret in the shell history, in `ps` output for
     * the life of the process, and in any command-line auditing the host does. The variable
     * name is what goes on the command line; the value never does.
     */
    private function passphrase(): string
    {
        $variable = $this->trimmedOption('passphrase-env');

        if ($variable === '') {
            return '';
        }

        $value = getenv($variable);

        if ($value === false) {
            $this->warn('The environment variable '.$variable.' is not set; treating the key as unencrypted.');

            return '';
        }

        return $value;
    }

    /**
     * The certificate currently in force, or null when none can be resolved.
     *
     * Resolved through the directory rather than through `SealIdentity` on purpose: the
     * directory does not reject an expired certificate, and "the certificate in force expired
     * last week" is one of the two most likely reasons somebody is running this command. A
     * rotation must not be blocked by the state it exists to repair.
     */
    private function outgoingCertificate(SealCertificateDirectory $directory, string $outgoingKeyId): ?SealCertificate
    {
        if ($outgoingKeyId === '') {
            return null;
        }

        try {
            return $directory->certificateFor($outgoingKeyId);
        } catch (Throwable) {
            return null;
        }
    }

    private function report(string $outgoingKeyId, ?SealCertificate $outgoing, SealMaterial $incoming): void
    {
        $this->table(['', 'Outgoing', 'Incoming'], [
            [
                'Key id',
                $outgoingKeyId === '' ? '(none configured)' : $outgoingKeyId,
                $incoming->keyId,
            ],
            [
                'Certificate subject',
                $outgoing?->subject ?? '(not resolvable here)',
                $incoming->subject,
            ],
            [
                'Certificate SHA-256',
                $outgoing?->fingerprint ?? '(not resolvable here)',
                $incoming->certificateFingerprint,
            ],
            [
                'Valid until',
                $outgoing === null ? '(not resolvable here)' : $outgoing->notAfter->format(DATE_ATOM),
                $incoming->notAfter->format(DATE_ATOM),
            ],
            [
                'Digest algorithm',
                (string) config('esign.seal.digest_algorithm', 'sha256'),
                $incoming->digestAlgorithm,
            ],
        ]);

        $this->info('The proposed material loads, the certificate is within its validity window, and the '
            .'private key matches it.');

        $remaining = $this->daysUntil($incoming->notAfter);
        $warnDays = (int) config('esign.health.cert_warn_days', 30);

        if ($remaining <= $warnDays) {
            $this->warn(sprintf(
                'The new certificate expires in %d day(s), inside the %d-day warning window. Rotating onto '
                .'material that is about to expire buys almost nothing.',
                $remaining,
                $warnDays,
            ));
        }

        if ($outgoing === null && $outgoingKeyId !== '') {
            $this->warn(
                'The certificate for the key id currently in force ("'.$outgoingKeyId.'") could not be '
                .'resolved on this host. Artifacts sealed under it cannot be verified here until it is '
                .'listed in ESIGN_SEAL_RETIRED_KEYS with a readable certificate path.'
            );
        }
    }

    /**
     * Print exactly what the operator changes, and nothing else.
     *
     * The retired-keys line is built from the current one with the outgoing key appended,
     * because that is the step a rotation forgets: dropping the outgoing key id out of the
     * list is what turns yesterday's artifacts into evidence nobody can attribute.
     */
    private function printEnvironmentChange(
        Repository $config,
        string $outgoingKeyId,
        string $keyId,
        string $certificatePath,
        string $privateKeyPath,
    ): void {
        $chain = $this->trimmedOption('chain');

        $this->line('');
        $this->line('Change these in the worker role\'s environment, then restart the worker:');
        $this->line('');
        $this->line('  ESIGN_SEAL_KEY_ID='.$keyId);
        $this->line('  ESIGN_SEAL_CERTIFICATE_PATH='.$certificatePath);
        $this->line('  ESIGN_SEAL_PRIVATE_KEY_PATH='.$privateKeyPath);
        $this->line('  ESIGN_SEAL_CHAIN_PATH='.$chain);

        if ($this->trimmedOption('passphrase-env') !== '') {
            $this->line('  ESIGN_SEAL_PRIVATE_KEY_PASSPHRASE=<the value of '.$this->trimmedOption('passphrase-env').'>');
        }

        if ($outgoingKeyId !== '') {
            $this->line('  ESIGN_SEAL_RETIRED_KEYS="'.$this->retiredKeysLine($config, $outgoingKeyId).'"');
        }

        $this->line('');
        $this->line('Then, in order:');
        $this->line('  1. php artisan config:clear   (or config:cache, if this deployment caches config)');
        $this->line('  2. restart the worker role. Web and scheduler do not seal.');
        $this->line('  3. php artisan esign:seal:status        — the new key id is in force');
        $this->line('  4. php artisan esign:artifacts:verify --key-id='.($outgoingKeyId === '' ? '<old id>' : $outgoingKeyId));
        $this->line('     — artifacts sealed under the retired key still verify.');
        $this->line('');
        $this->line('Keep the retired *certificate* forever. Destroy the retired *private key* on the');
        $this->line('schedule your key policy sets: verification never needs it.');
    }

    private function retiredKeysLine(Repository $config, string $outgoingKeyId): string
    {
        $entries = [];

        /** @var iterable<mixed> $configured */
        $configured = is_iterable($config->get('esign.seal.retired_keys')) ? $config->get('esign.seal.retired_keys') : [];

        foreach ($configured as $entry) {
            if (! is_array($entry) || ($entry['key_id'] ?? '') === $outgoingKeyId) {
                continue;
            }

            $entries[] = implode('|', array_filter([
                (string) ($entry['key_id'] ?? ''),
                (string) ($entry['certificate_path'] ?? ''),
                (string) ($entry['chain_path'] ?? ''),
            ], static fn (string $field): bool => $field !== ''));
        }

        $entries[] = implode('|', array_filter([
            $outgoingKeyId,
            (string) $config->get('esign.seal.certificate_path', ''),
            (string) $config->get('esign.seal.chain_path', ''),
        ], static fn (string $field): bool => $field !== ''));

        return implode(',', $entries);
    }

    private function trimmedOption(string $name): string
    {
        $value = $this->option($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function daysUntil(DateTimeImmutable $notAfter): int
    {
        return (int) floor(($notAfter->getTimestamp() - time()) / 86_400);
    }
}
