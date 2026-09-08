<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

/**
 * Envelopes that are `finalizing` and that nothing appears to be working on.
 *
 * One reader, two callers, on purpose: `esign:finalization:resume` re-dispatches exactly the
 * set the `finalization_backlog` readiness probe counts. If they asked the question
 * differently, an operator could see a backlog the sweep would never clear, or a clean probe
 * beside a command that kept re-queueing work.
 *
 * ## What "waiting" is measured from
 *
 * The latest `finalization_runs` row for the envelope if there is one, and the envelope's own
 * `updated_at` if there is not. Both are the same fact from either side of the handover: the
 * envelope's timestamp is when the last acceptance put it in `finalizing`, and a run's
 * `started_at` is when a worker last picked the work up. An envelope with no run has had no
 * worker; an envelope whose newest run started two minutes ago has one, and interrupting it
 * would only allocate a competing generation.
 *
 * A latest run in `published` is excluded outright. It should be impossible — publication and
 * `markCompleted()` are one transaction — but if the envelope is somehow still `finalizing`
 * behind a published run, re-dispatching cannot help and the compare-and-swap would refuse it
 * anyway.
 *
 * States other than `finalizing` are not this class's business. `finalization_failed` is a
 * visible state an operator retries deliberately (`docs/signing/state-machine.md`), and
 * `completed` is done.
 */
final class StalledFinalizations
{
    /**
     * Envelopes waiting since at or before `$cutoff`, oldest first.
     *
     * @return array<string, CarbonImmutable> Envelope public id => waiting since.
     */
    public function before(CarbonImmutable $cutoff): array
    {
        /** @var Collection<int, Envelope> $envelopes */
        $envelopes = Envelope::query()
            ->where('state', EnvelopeState::Finalizing->value)
            ->orderBy('id')
            ->get(['id', 'public_id', 'updated_at']);

        if ($envelopes->isEmpty()) {
            return [];
        }

        $runs = $this->latestRuns($envelopes->modelKeys());
        $stalled = [];

        foreach ($envelopes as $envelope) {
            $run = $runs[$envelope->getKey()] ?? null;

            if ($run !== null && $run->state === FinalizationRunState::Published) {
                continue;
            }

            $waitingSince = $run?->started_at ?? $envelope->updated_at;

            if ($waitingSince === null || $waitingSince->greaterThan($cutoff)) {
                continue;
            }

            $stalled[$envelope->public_id] = $waitingSince;
        }

        uasort($stalled, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $stalled;
    }

    /**
     * The newest run per envelope, in one query.
     *
     * `MAX(id)` rather than `MAX(started_at)`: generations are allocated under the envelope
     * lock in insertion order, and two runs can share a second on a coarse clock.
     *
     * @param  list<int|string>  $envelopeIds
     * @return array<int, FinalizationRun>
     */
    private function latestRuns(array $envelopeIds): array
    {
        /** @var array<int, FinalizationRun> $runs */
        $runs = FinalizationRun::query()
            ->whereIn('id', function (Builder $query) use ($envelopeIds): void {
                $query->from('finalization_runs')
                    ->selectRaw('MAX(id)')
                    ->whereIn('envelope_id', $envelopeIds)
                    ->groupBy('envelope_id');
            })
            ->get()
            ->keyBy('envelope_id')
            ->all();

        return $runs;
    }
}
