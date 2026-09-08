<?php

use App\Domain\Delivery\Health\SchedulerHeartbeat;
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
 * Native API idempotency keys expire after 24 hours (App\Domain\Integration\Native\IdempotencyStore::TTL_HOURS)
 * and are removed here.
 * The table is operational scratch: without a prune it grows with every mutating API call
 * forever, and the replay guarantee only ever covers a day, so nothing of value is lost.
 * Hourly rather than daily so a busy deployment never carries more than an hour of dead rows.
 */
Schedule::command('esign:api:prune-idempotency-keys')
    ->hourly()
    ->name('api:prune-idempotency-keys')
    ->withoutOverlapping();
