<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Console;

use App\Domain\Evidence\Finalization\FinalizationTrigger;
use App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope;
use App\Domain\Evidence\Finalization\StalledFinalizations;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * The safety net under {@see FinalizationTrigger}.
 * Scheduled every five minutes in `routes/console.php`.
 *
 * The trigger dispatches once, after the last acceptance commits. Everything that can happen
 * to a queued job afterwards — a worker killed before it started, a `jobs` row lost with a
 * database restore, a queue purged during an incident, a bounded cPanel worker that stopped
 * being invoked — leaves an envelope in `finalizing` with nobody coming for it, and no
 * further transition will ever occur to notice. This command is the only thing that does.
 *
 * It is a prompt, not an authority: {@see FinalizeEnvelope} allocates its own generation
 * under the envelope lock, re-checks the state, and the publishing transaction's
 * compare-and-swap lets exactly one attempt publish. A re-dispatch for an envelope a worker
 * is quietly still finalizing therefore loses the race rather than corrupting anything, and a
 * retry that finds bytes already uploaded republishes those instead of re-sealing.
 *
 * What it never touches:
 *
 * - **`finalization_failed`.** A visible failure is retried by an operator with
 *   `EnvelopeStateMachine::retryFinalization()` and a fresh dispatch, deliberately
 *   (`docs/signing/state-machine.md`). Sweeping it here would turn one legible failure into a
 *   loop of them every five minutes.
 * - **Anything that already completed**, or is cancelled, declined, or expired.
 * - **An envelope whose newest run started inside the window.** That is a worker doing its
 *   job; sealing is slow.
 */
final class ResumeCommand extends Command
{
    protected $signature = 'esign:finalization:resume';

    protected $description = 'Re-dispatch finalization for envelopes stuck in finalizing.';

    public function handle(StalledFinalizations $stalled, Repository $config): int
    {
        $minutes = max(1, (int) $config->get('esign.finalization.resume_after_minutes', 10));
        $waiting = $stalled->before(CarbonImmutable::now()->subMinutes($minutes));

        foreach (array_keys($waiting) as $envelopePublicId) {
            FinalizeEnvelope::dispatch($envelopePublicId);
        }

        $this->components->info(
            $waiting === []
                ? "No envelope has been waiting to finalize for more than {$minutes} minute(s)."
                : 'Re-dispatched finalization for '.count($waiting).' envelope(s).'
        );

        return self::SUCCESS;
    }
}
