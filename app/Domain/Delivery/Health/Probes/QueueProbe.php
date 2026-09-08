<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\ProbeResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports queue backlog age (oldest pending job's available_at) and how many
 * jobs failed in the last 24 hours. Reads the `jobs` and `failed_jobs`
 * tables directly rather than draining the queue.
 */
final class QueueProbe implements HealthProbe
{
    public function name(): string
    {
        return 'queue';
    }

    public function check(): ProbeResult
    {
        try {
            $connection = DB::connection($this->connectionName());

            $oldestAvailableAt = $connection->table('jobs')->min('available_at');
            $failedLast24h = $connection->table('failed_jobs')
                ->where('failed_at', '>=', CarbonImmutable::now()->subDay())
                ->count();
        } catch (Throwable) {
            return ProbeResult::fail($this->name(), 'Queue tables are unavailable.');
        }

        $lagStatus = HealthStatus::Ok;
        $lagSeconds = null;

        if ($oldestAvailableAt !== null) {
            $lagSeconds = max(0, CarbonImmutable::now()->getTimestamp() - (int) $oldestAvailableAt);
            $warnAt = (int) config('esign.health.queue_warn_seconds', 120);
            $failAt = (int) config('esign.health.queue_fail_seconds', 600);

            $lagStatus = match (true) {
                $lagSeconds >= $failAt => HealthStatus::Fail,
                $lagSeconds >= $warnAt => HealthStatus::Warn,
                default => HealthStatus::Ok,
            };
        }

        $failedStatus = $failedLast24h > 0 ? HealthStatus::Warn : HealthStatus::Ok;

        $status = HealthStatus::worst([$lagStatus, $failedStatus]);

        $lagMessage = $oldestAvailableAt === null
            ? 'No pending jobs.'
            : "Oldest pending job is {$lagSeconds}s old.";

        $message = "{$lagMessage} {$failedLast24h} job(s) failed in the last 24h.";

        return new ProbeResult($this->name(), $status, $message);
    }

    private function connectionName(): ?string
    {
        $queueConnection = config('queue.default');

        return config("queue.connections.{$queueConnection}.connection") ?? config('database.default');
    }
}
