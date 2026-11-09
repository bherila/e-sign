<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing\Console;

use App\Domain\Evidence\Sealing\Readiness\SealReadinessAttester;
use Illuminate\Console\Command;

/**
 * Record whether the seal material is usable, from the process it is mounted into (issue #37).
 *
 * The worker role runs this at start. It always exits 0 once it has recorded an answer: an
 * unusable key is a recorded reason the send gate reports, not a reason to keep the worker,
 * which also sends mail, from starting.
 */
final class SealAttestCommand extends Command
{
    protected $signature = 'esign:seal:attest';

    protected $description = 'Check the service seal material where it is mounted and record the result for the send gate.';

    public function handle(SealReadinessAttester $attester): int
    {
        $attestation = $attester->attest();

        foreach ($attestation->levels as $level => $reason) {
            $reason === null
                ? $this->components->info("{$level}: available")
                : $this->components->warn("{$level}: unavailable — {$reason}");
        }

        return self::SUCCESS;
    }
}
