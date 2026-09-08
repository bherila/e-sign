<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Console;

use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * Reports which seal material this deployment is configured with, and whether it is usable.
 *
 * The operational half of issue #29: rotation, expiry monitoring, and compromise response all
 * start with being able to answer "which key is in force here, and until when" without
 * opening a document or reading a key file by hand. See
 * docs/operations/seal-key-management.md.
 *
 * **Nothing here prints, logs, or derives anything from the private key.** It reads the
 * certificate's own public facts through {@see SealIdentity}, which has no method that could
 * return key material even if this command asked for one. The exit status is the interesting
 * part for a monitoring hook: 0 when the material is usable and not expiring inside the
 * warning window, 1 otherwise, so `esign:seal:status` can be a cron check rather than
 * something a person has to read.
 *
 * It also reports the **retired** key versions, which is the half a rotation makes matter.
 * Retired keys are listed with their certificates' state, and an expired retired certificate
 * is reported as expired without being reported as a problem: the artifacts it sealed were
 * sealed while it was valid, and the deployment keeps it only so those artifacts stay
 * attributable. A retired certificate that cannot be *loaded* is a different matter and does
 * fail the exit status, because it means published evidence has become unattributable here.
 */
final class SealStatusCommand extends Command
{
    protected $signature = 'esign:seal:status
        {--warn-days= : Days of remaining validity below which this reports a warning (default: the health probe threshold)}';

    protected $description = 'Report the configured service seal material: key id, subject, expiry, fingerprint, and TSA';

    public function handle(SealIdentity $seal, SealCertificateDirectory $directory): int
    {
        $warnDays = $this->warnDays();

        try {
            $notAfter = $seal->notAfter();
            $rows = [
                ['Key id', $seal->keyId()],
                ['Certificate subject', $seal->subject()],
                ['Certificate SHA-256', $seal->certificateFingerprint()],
                ['Digest algorithm', $seal->digestAlgorithm()],
                ['Valid until', $notAfter->format(DATE_ATOM)],
                ['Days remaining', (string) $this->daysUntil($notAfter)],
                ['Chain configured', $seal->chainPem() === '' ? 'no' : 'yes'],
            ];
        } catch (SealingException $exception) {
            $this->error('The seal material is not usable: '.$exception->getMessage());
            $this->line('');
            $this->line('This deployment cannot send an envelope at any assurance level until it is fixed.');
            $this->line('See docs/operations/seal-key-management.md.');

            return self::FAILURE;
        }

        $timestampAuthority = $seal->hasTimestampAuthority();

        $rows[] = ['Timestamp authority', $timestampAuthority ? 'configured' : 'not configured'];
        $rows[] = [
            'Reachable assurance levels',
            $timestampAuthority
                ? implode(', ', [AssuranceLevel::PadesBB->value, AssuranceLevel::PadesBT->value])
                : AssuranceLevel::PadesBB->value,
        ];

        $this->table(['Property', 'Value'], $rows);

        $retiredIsBroken = $this->reportRetired($directory);

        if (! $timestampAuthority) {
            $this->comment(
                'No RFC 3161 timestamp authority is configured, so PAdES '.AssuranceLevel::PadesBT->value
                .' cannot be produced. An envelope that asks for it is refused before signers are '
                .'invited; it is never downgraded.'
            );
        }

        $remaining = $this->daysUntil($notAfter);

        if ($retiredIsBroken) {
            return self::FAILURE;
        }

        if ($remaining <= $warnDays) {
            $this->warn(sprintf(
                'The seal certificate expires in %d day(s). Rotation means a new key id and new files, '
                .'not a replacement of these ones: artifacts already sealed stay verifiable through the '
                .'certificate embedded in their own CMS.',
                $remaining,
            ));

            return self::FAILURE;
        }

        $this->info('The configured seal material is usable.');

        return self::SUCCESS;
    }

    /**
     * List the retired key versions and whether each certificate still loads.
     *
     * @return bool True when at least one retired certificate could not be loaded, which is an
     *              evidence gap and must be reflected in the exit status.
     */
    private function reportRetired(SealCertificateDirectory $directory): bool
    {
        $retired = $directory->retiredKeyIds();

        if ($retired === []) {
            $this->line('');
            $this->line('No retired seal keys are configured (ESIGN_SEAL_RETIRED_KEYS is empty). That is '
                .'correct for a deployment that has never rotated. After a rotation, the outgoing key\'s '
                .'certificate belongs here or its artifacts stop being verifiable.');

            return false;
        }

        $rows = [];
        $broken = false;

        foreach ($retired as $keyId) {
            $problem = $directory->problemWith($keyId);

            if ($problem !== null) {
                $broken = true;
                $rows[] = [$keyId, 'UNRESOLVABLE', '', $problem];

                continue;
            }

            $certificate = $directory->certificateFor($keyId);

            $rows[] = [
                $keyId,
                // Expired is the expected state for a retired key, not a fault: the artifacts
                // it sealed were sealed while it was valid.
                $certificate->isExpired() ? 'expired (fine)' : 'still valid',
                substr($certificate->fingerprint, 0, 16).'…',
                $certificate->notAfter->format(DATE_ATOM),
            ];
        }

        $this->line('');
        $this->line('Retired seal keys — kept so artifacts sealed before a rotation stay verifiable:');
        $this->table(['Key id', 'Certificate', 'SHA-256', 'Valid until / problem'], $rows);

        if ($broken) {
            $this->error(
                'At least one retired seal certificate could not be loaded. Artifacts sealed under that '
                .'key id cannot be verified on this deployment until it is. Only the certificate is '
                .'needed; the retired private key is not, and must not be restored for this.'
            );
        }

        return $broken;
    }

    private function warnDays(): int
    {
        $option = $this->option('warn-days');

        return $option === null
            ? (int) config('esign.health.cert_warn_days', 30)
            : max(0, (int) $option);
    }

    private function daysUntil(DateTimeImmutable $notAfter): int
    {
        return (int) floor(($notAfter->getTimestamp() - time()) / 86_400);
    }
}
