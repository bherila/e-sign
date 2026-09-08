<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * A scheduled task (routes/console.php) calls record() every minute. The
 * scheduler heartbeat probe reads lastRecordedAt() to detect a stuck or
 * absent scheduler (e.g. cron not configured, `schedule:run` not wired up).
 */
final class SchedulerHeartbeat
{
    public const CACHE_KEY = 'esign:health:scheduler-heartbeat';

    public function record(): void
    {
        Cache::forever(self::CACHE_KEY, CarbonImmutable::now()->toIso8601String());
    }

    public function lastRecordedAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
