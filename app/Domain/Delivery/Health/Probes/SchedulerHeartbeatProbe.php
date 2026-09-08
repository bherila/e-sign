<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Delivery\Health\SchedulerHeartbeat;
use Carbon\CarbonImmutable;

/**
 * The scheduled task in routes/console.php refreshes a cache key every
 * minute; an absent or stale heartbeat means cron/`schedule:run` is not
 * actually driving the scheduler.
 */
final class SchedulerHeartbeatProbe implements HealthProbe
{
    public function __construct(private readonly SchedulerHeartbeat $heartbeat) {}

    public function name(): string
    {
        return 'scheduler';
    }

    public function check(): ProbeResult
    {
        $lastRecordedAt = $this->heartbeat->lastRecordedAt();

        if ($lastRecordedAt === null) {
            return ProbeResult::fail($this->name(), 'No scheduler heartbeat has been recorded.');
        }

        $ageSeconds = max(0, CarbonImmutable::now()->getTimestamp() - $lastRecordedAt->getTimestamp());
        $warnAt = (int) config('esign.health.scheduler_warn_seconds', 180);
        $failAt = (int) config('esign.health.scheduler_fail_seconds', 600);

        if ($ageSeconds >= $failAt) {
            return ProbeResult::fail($this->name(), "Scheduler heartbeat is {$ageSeconds}s old.");
        }

        if ($ageSeconds >= $warnAt) {
            return ProbeResult::warn($this->name(), "Scheduler heartbeat is {$ageSeconds}s old.");
        }

        return ProbeResult::ok($this->name(), "Scheduler heartbeat is {$ageSeconds}s old.");
    }
}
