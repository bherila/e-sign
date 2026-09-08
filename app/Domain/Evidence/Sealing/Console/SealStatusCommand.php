<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Console;

use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Exceptions\SealingException;
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
 */
final class SealStatusCommand extends Command
{
    protected $signature = 'esign:seal:status
        {--warn-days= : Days of remaining validity below which this reports a warning (default: the health probe threshold)}';

    protected $description = 'Report the configured service seal material: key id, subject, expiry, fingerprint, and TSA';

    public function handle(SealIdentity $seal): int
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

        if (! $timestampAuthority) {
            $this->comment(
                'No RFC 3161 timestamp authority is configured, so PAdES '.AssuranceLevel::PadesBT->value
                .' cannot be produced. An envelope that asks for it is refused before signers are '
                .'invited; it is never downgraded.'
            );
        }

        $remaining = $this->daysUntil($notAfter);

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
