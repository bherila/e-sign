<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\SchedulerHeartbeatProbe;
use App\Domain\Delivery\Health\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHeartbeatProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_fails_when_no_heartbeat_has_ever_been_recorded(): void
    {
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);

        $result = $this->app->make(SchedulerHeartbeatProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_is_ok_immediately_after_recording(): void
    {
        $this->app->make(SchedulerHeartbeat::class)->record();

        $result = $this->app->make(SchedulerHeartbeatProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_warns_when_heartbeat_is_stale(): void
    {
        $this->app->make(SchedulerHeartbeat::class)->record();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(200));

        $result = $this->app->make(SchedulerHeartbeatProbe::class)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
    }

    public function test_fails_when_heartbeat_is_very_stale(): void
    {
        $this->app->make(SchedulerHeartbeat::class)->record();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(700));

        $result = $this->app->make(SchedulerHeartbeatProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
