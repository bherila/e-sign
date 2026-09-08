<?php

declare(strict_types=1);

namespace App\Domain\Identity\Credentials\Console;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;

/**
 * Revoke a credential immediately. There is no un-revoke.
 */
class RevokeServiceCredentialCommand extends ServiceCredentialCommand
{
    protected $signature = 'esign:credential:revoke
        {prefix : The public prefix of the credential to revoke, e.g. esk_k3n9x2ab7q1z}
        {--reason= : Recorded in the audit event, e.g. "secret pasted into a ticket"}';

    protected $description = 'Revoke an API credential immediately.';

    public function handle(ServiceCredentialIssuer $issuer): int
    {
        $credential = $this->credential((string) $this->argument('prefix'));

        if (! $credential instanceof ServiceCredential) {
            return self::FAILURE;
        }

        if ($credential->isRevoked()) {
            $this->info("Credential {$credential->prefix} was already revoked at {$credential->revoked_at?->toIso8601String()}.");
            $this->line('Nothing to do; revocation is not repeated and writes no second audit event.');

            return self::SUCCESS;
        }

        $issuer->revoke(
            $credential,
            AuditActor::console($this->name ?? 'esign:credential:revoke'),
            $this->trimmedOption('reason'),
        );

        $this->info("Revoked {$credential->prefix}. Every request presenting it now fails with 401.");
        $this->presentCredential($credential, [
            ['revoked at', $credential->revoked_at?->toIso8601String() ?? ''],
        ]);

        return self::SUCCESS;
    }
}
