<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Queue\Console;

use App\Domain\Delivery\Queue\WorkerLeaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The cPanel/shared-hosting substitute for a persistent queue daemon (docs/HANDOFF.md section
 * 13, issue #39). A cron job runs this once a minute; it takes a database lease so a second
 * cron tick that fires while the first is still running refuses to start a competing worker,
 * then runs `queue:work --stop-when-empty --max-time=<n> --max-jobs=<n>` on the `database`
 * queue connection, heartbeating the lease between jobs, and releases the lease on exit.
 *
 * ## What this does not, and cannot, do
 *
 * `--max-time` (and therefore this command) is **not a hard per-job interrupt**. `queue:work`
 * only checks its time and job-count budget *between* jobs; a single job already running when
 * the budget is exhausted finishes uninterrupted before the process exits. That is why the
 * lease TTL (`esign.queue.lease_ttl`) must be configured comfortably larger than `max_time`
 * plus the longest job's own timeout: a lease that expired exactly at `max_time` would let the
 * *next* cron tick decide a still-legitimately-running worker was abandoned and start a second
 * one over the same queue rows. Oversized PDFs must fail preflight before an invitation is ever
 * sent (docs/HANDOFF.md section 13) rather than depend on this command to interrupt a hung job;
 * this command cannot do that on a host without pcntl/process-spawning capabilities, and does
 * not try to.
 *
 * ## Heartbeats happen between jobs, not on a timer thread
 *
 * There is no persistent daemon, no forked process, and no pcntl signal handler here — all of
 * which a shared-hosting account may not be able to offer. `queue:work` runs the loop in this
 * same PHP process; a `Looping` listener registered here fires once per loop iteration (before
 * each job attempt and on every idle poll) and heartbeats the lease if at least
 * `esign.queue.heartbeat` seconds have passed since the last one. If a heartbeat reports that
 * the lease was taken over by another process (this one presumably hung or was killed and
 * missed its TTL), the listener flags the running worker to stop via Laravel's own
 * `queue:restart` cache signal — the same mechanism `php artisan queue:restart` uses — so the
 * loop exits at its next iteration instead of continuing to work a queue this process no
 * longer has the lease for.
 */
final class WorkBoundedCommand extends Command
{
    private const RESTART_CACHE_KEY = 'illuminate:queue:restart';

    protected $signature = 'esign:queue:work-bounded
        {--queue= : Comma-separated queue names (default: default plus the configured mail/webhook queues)}
        {--max-time= : Seconds queue:work runs before stopping even if jobs remain (default esign.queue.max_time)}
        {--max-jobs= : Jobs processed before queue:work stops (default esign.queue.max_jobs)}
        {--lease-ttl= : Seconds a lease stays valid without a heartbeat before another tick may take it over (default esign.queue.lease_ttl)}
        {--heartbeat= : Seconds between lease heartbeats while the worker runs (default esign.queue.heartbeat)}
        {--lease-name=esign-queue-worker : Lease row name; change only when running more than one bounded-worker profile}';

    protected $description = 'Run queue:work bounded by time/job count, guarded by a database lease so overlapping cron ticks never run two workers at once.';

    public function handle(WorkerLeaseManager $leases, Dispatcher $events): int
    {
        $leaseName = (string) $this->option('lease-name');
        $maxTime = $this->intOption('max-time', (int) config('esign.queue.max_time', 50));
        $maxJobs = $this->intOption('max-jobs', (int) config('esign.queue.max_jobs', 100));
        $leaseTtl = $this->intOption('lease-ttl', (int) config('esign.queue.lease_ttl', 900));
        $heartbeatInterval = $this->intOption('heartbeat', (int) config('esign.queue.heartbeat', 10));

        if ($leaseTtl <= $maxTime) {
            $this->error(sprintf(
                '--lease-ttl (%ds) must be greater than --max-time (%ds); it exists to outlast max-time plus the '
                .'longest job timeout, since max-time is not a hard per-job interrupt. Refusing to start.',
                $leaseTtl,
                $maxTime,
            ));

            return self::INVALID;
        }

        $holderToken = (string) Str::uuid();
        $now = CarbonImmutable::now();

        if (! $leases->acquire($leaseName, $holderToken, $now, $leaseTtl)) {
            $this->info("Lease '{$leaseName}' is already held by a live worker. Exiting without starting one.");

            return self::SUCCESS;
        }

        $lastHeartbeatAt = $now;
        $lostLease = false;

        $listener = function () use (
            $leases,
            $leaseName,
            $holderToken,
            $heartbeatInterval,
            $leaseTtl,
            &$lastHeartbeatAt,
            &$lostLease,
        ): void {
            if ($lostLease) {
                return;
            }

            $tick = CarbonImmutable::now();

            if ($tick->diffInSeconds($lastHeartbeatAt) < $heartbeatInterval) {
                return;
            }

            $lastHeartbeatAt = $tick;

            if (! $leases->heartbeat($leaseName, $holderToken, $tick, $leaseTtl)) {
                $lostLease = true;
                // Same signal `php artisan queue:restart` sends: the running worker checks
                // this cache key once per loop iteration and stops cleanly at the next one.
                Cache::forever(self::RESTART_CACHE_KEY, $tick->getTimestamp());
            }
        };

        $events->listen(Looping::class, $listener);

        try {
            $exitCode = Artisan::call('queue:work', [
                '--queue' => $this->queueNames(),
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
                '--max-jobs' => $maxJobs,
            ], $this->output);
        } finally {
            // A no-op if $lostLease is true: release() only deletes the row when
            // $holderToken still matches, so it never deletes the new holder's lease.
            $leases->release($leaseName, $holderToken);
        }

        if ($lostLease) {
            $this->error("Lease '{$leaseName}' was taken over by another worker before this one finished; stopped early.");

            return self::FAILURE;
        }

        return $exitCode;
    }

    private function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    private function queueNames(): string
    {
        $option = $this->option('queue');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $queues = array_unique([
            'default',
            (string) config('esign.mail.queue', 'mail'),
            (string) config('esign.delivery.webhooks.queue', 'default'),
        ]);

        return implode(',', $queues);
    }
}
