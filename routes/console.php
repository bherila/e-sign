<?php

use App\Domain\Delivery\Events\Console\ExpireCommand;
use App\Domain\Delivery\Events\Console\RemindCommand;
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
