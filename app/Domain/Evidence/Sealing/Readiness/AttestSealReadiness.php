<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Readiness;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-check the seal material on the worker, where it is mounted (issue #37).
 *
 * Scheduled every five minutes when `ESIGN_SEAL_READINESS=attested`. Queued rather than run by
 * the scheduler itself because the scheduler role has no key material in the Docker layout; the
 * worker that picks this up does. One attempt: a failure is recorded as a reason, and the next
 * tick tries again.
 */
final class AttestSealReadiness implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(SealReadinessAttester $attester): void
    {
        $attester->attest();
    }
}
