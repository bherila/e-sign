<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials\Console;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use InvalidArgumentException;
use RuntimeException;

/**
 * Rotate a credential: mint a successor and give the old secret a deadline.
 *
 * The overlap is what makes rotation deployable — the consumer can be redeployed with the
 * new secret while the old one still answers — and it is also the thing you do not want
 * after a leak, so `--overlap=0h` cuts over immediately.
 */
class RotateServiceCredentialCommand extends ServiceCredentialCommand
{
    protected $signature = 'esign:credential:rotate
        {prefix : The public prefix of the credential to rotate, e.g. esk_k3n9x2ab7q1z}
        {--overlap= : How much longer the old secret works (default 24h). Use 0h to cut over now}
        {--expires= : Expiry for the new secret: an interval (30d) or a date. Defaults to the old one\'s}';

    protected $description = 'Issue a replacement secret for a credential, with an overlap window for the old one.';

    public function handle(ServiceCredentialIssuer $issuer): int
    {
        $credential = $this->credential((string) $this->argument('prefix'));

        if (! $credential instanceof ServiceCredential) {
            return self::FAILURE;
        }

        $overlapOption = $this->trimmedOption('overlap');
        $overlap = null;

        if ($overlapOption !== null) {
            $overlap = $this->interval($overlapOption);

            if ($overlap === null) {
                return self::FAILURE;
            }
        }

        $expiresOption = $this->trimmedOption('expires');
        $expiry = $this->expiry($expiresOption);

        if ($expiresOption !== null && $expiry === null) {
            return self::FAILURE;
        }

        try {
            $issued = $issuer->rotate(
                $credential,
                AuditActor::console($this->name ?? 'esign:credential:rotate'),
                $overlap,
                $expiry,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->refuseWith($exception->getMessage());
        }

        $previousEnds = $issued->replaced?->expires_at?->toIso8601String() ?? 'immediately';

        $this->info("Rotated {$credential->prefix} to {$issued->credential->prefix}.");
        $this->line("The previous secret keeps working until {$previousEnds}; after that it fails closed with 401.");
        $this->presentSecret($issued);
        $this->presentCredential($issued->credential, [
            ['rotated from', $credential->prefix],
            ['previous secret works until', $previousEnds],
        ]);

        return self::SUCCESS;
    }
}
