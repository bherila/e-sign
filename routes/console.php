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
