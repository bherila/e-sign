<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Evidence\Finalization\StalledFinalizations;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Envelopes that everybody has signed and that nothing is finalizing.
 *
 * Distinct from the `queue` probe, which measures the queue. A finalization can be missing
 * from the queue entirely — the job was lost, the worker was killed before it started, the
 * `jobs` table was restored from a backup — and that is precisely the case where the queue
 * looks empty and healthy while a signer is waiting for an agreement that will never arrive.
 *
 * It counts the same set `esign:finalization:resume` re-dispatches (see
 * {@see StalledFinalizations}), so anything this reports is something the five-minute sweep
 * is about to act on. **Any** such envelope warns: the sweep clears a lost job within one
 * tick, so a backlog that persists into the next scrape means the sweep is not running or the
 * work is not being picked up, and that is worth an operator's attention rather than nothing.
 *
 * | Condition | Status |
 * |---|---|
 * | Nothing has been waiting longer than `esign.finalization.resume_after_minutes` | `ok` |
 * | At least one envelope has | `warn` |
 * | More than 10 have, or any one of them has waited an hour | `fail` |
 *
 * An hour is a deliberate multiple of the ten-minute window rather than a sealing timeout: by
 * then the resume sweep has had five chances and the work is still not happening, which is a
 * stopped worker, not a slow one.
 *
 * The message carries counts and an age, never an envelope id, a title, or a workspace — the
 * same rule every probe in this directory follows (`docs/operations/health.md`).
 */
final class FinalizationBacklogProbe implements HealthProbe
{
    /** More than this many waiting envelopes is an outage, not a hiccup. */
    private const FAIL_COUNT = 10;

    /** Seconds. One envelope waiting this long has outlived five resume sweeps. */
    private const FAIL_AGE_SECONDS = 3_600;

    public function __construct(private readonly StalledFinalizations $stalled) {}

    public function name(): string
    {
        return 'finalization_backlog';
    }

    public function check(): ProbeResult
    {
        $minutes = max(1, (int) config('esign.finalization.resume_after_minutes', 10));
        $now = CarbonImmutable::now();

        try {
            $waiting = $this->stalled->before($now->subMinutes($minutes));
        } catch (Throwable) {
            // Never the table name or the driver: the readiness body is returned to a caller
            // with an operator token, not necessarily to an operator.
            return ProbeResult::fail($this->name(), 'The finalization backlog could not be read.');
        }

        if ($waiting === []) {
            return ProbeResult::ok(
                $this->name(),
                "No envelope has been waiting to finalize for more than {$minutes} minute(s).",
            );
        }

        $count = count($waiting);
        $oldestSeconds = max(0, $now->getTimestamp() - (int) reset($waiting)->getTimestamp());

        $status = $count > self::FAIL_COUNT || $oldestSeconds >= self::FAIL_AGE_SECONDS
            ? HealthStatus::Fail
            : HealthStatus::Warn;

        return new ProbeResult($this->name(), $status, sprintf(
            '%d envelope(s) have been waiting to finalize for more than %d minute(s); the oldest for %d minute(s).',
            $count,
            $minutes,
            intdiv($oldestSeconds, 60),
        ));
    }
}
