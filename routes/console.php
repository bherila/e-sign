<?php

use App\Domain\Delivery\Events\Console\ExpireCommand;
use App\Domain\Delivery\Events\Console\RemindCommand;
use App\Domain\Delivery\Health\SchedulerHeartbeat;
use App\Domain\Evidence\Finalization\Console\ResumeCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refreshes the cache key the scheduler-heartbeat health probe reads (issue #16). If
// this stops updating, cron/`schedule:run` is not actually driving the scheduler.
Schedule::call(fn () => app(SchedulerHeartbeat::class)->record())
    ->everyMinute()
    ->name('health:scheduler-heartbeat');

/*
 * The two things about an envelope that only the clock can cause (issue #35).
 *
 * Expiry runs first and hourly, because an expiry that has arrived is a state the product
 * claims is true and every hour it is not applied is an hour a signer can still act on an
 * agreement that should have closed. Reminders run once a day: their own thresholds decide
 * who is due, so a daily pass is a floor on the delay and never a cause of a second message.
 *
 * `withoutOverlapping()` on both. On the cPanel profile cron can start a run while the last
 * one is still going; the row-level claims in the schedulers already make a double send
 * impossible, and this keeps the queue from filling with work that will decide to do nothing.
 */
Schedule::command(ExpireCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->name('signing:expire');

Schedule::command(RemindCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->name('signing:remind');

/*
 * Native API idempotency keys expire after 24 hours
 * (App\Domain\Integration\Native\IdempotencyStore::TTL_HOURS) and are removed here.
 *
 * The table is operational scratch: without a prune it grows with every mutating API call
 * forever, and the replay guarantee only ever covers a day, so nothing of value is lost.
 * Hourly rather than daily so a busy deployment never carries more than an hour of dead rows.
 */
Schedule::command('esign:api:prune-idempotency-keys')
    ->hourly()
    ->withoutOverlapping()
    ->name('api:prune-idempotency-keys');

/*
 * Re-read every published artifact once a week and check it still matches its row
 * (issue #40; docs/operations/retention.md).
 *
 * Weekly rather than daily because the work is proportional to the whole corpus — every
 * artifact is streamed back and re-hashed, and the executed PDFs are re-validated on top —
 * and the failure it exists to catch is slow: bit rot, a half-restored object store, an
 * object replaced out of band. Sunday at 03:10 keeps it away from the hourly expiry pass
 * and the daily reminder pass.
 *
 * The `artifact_integrity` readiness probe reads the row this records rather than doing the
 * work itself, and warns once the last completed run is older than
 * `esign.retention.verification_warn_days` (8, one day of slack past this schedule). So a
 * verification that stops running is visible, not just one that fails.
 */
Schedule::command('esign:artifacts:verify')
    ->weeklyOn(0, '03:10')
    ->withoutOverlapping()
    ->name('artifacts:verify');

/*
 * The safety net under the finalization trigger (issue #94).
 *
 * `App\Domain\Evidence\Finalization\FinalizationTrigger` queues the work when the last
 * recipient signs, and that is how every healthy finalization starts. This pass exists for
 * the job that never ran: a worker killed before it picked the job up, a `jobs` table
 * restored from a backup, a queue purged during an incident. Nothing further happens to an
 * envelope in `finalizing`, so no later transition would ever notice.
 *
 * Every five minutes because the wait is a signer's: the agreement is executed and only the
 * artifact is missing. The command itself only acts on envelopes that have been waiting
 * longer than `esign.finalization.resume_after_minutes` (default 10), so a tick never
 * interrupts a worker that is simply still sealing, and `finalization_failed` is left alone
 * for the operator's explicit retry.
 *
 * `withoutOverlapping()` for the same reason as the passes above: a slow tick on the cPanel
 * profile must not stack a second sweep queueing the same envelopes again. Re-dispatching is
 * safe — the publishing compare-and-swap decides the race — but it would fill the queue with
 * work that will decide to do nothing.
 */
Schedule::command(ResumeCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('finalization:resume');
