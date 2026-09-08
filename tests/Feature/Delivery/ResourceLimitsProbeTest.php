<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\ResourceLimitsProbe;
use Tests\TestCase;

class ResourceLimitsProbeTest extends TestCase
{
    private string $originalMemoryLimit;

    private string $originalMaxExecutionTime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMemoryLimit = (string) ini_get('memory_limit');
        $this->originalMaxExecutionTime = (string) ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->originalMemoryLimit);
        ini_set('max_execution_time', $this->originalMaxExecutionTime);

        parent::tearDown();
    }

    public function test_is_ok_when_both_limits_meet_the_configured_minimums(): void
    {
        config()->set('esign.cpanel.min_memory_bytes', 256 * 1024 * 1024);
        config()->set('esign.cpanel.min_execution_seconds', 60);
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '120');

        $result = $this->app->make(ResourceLimitsProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_is_ok_when_both_limits_are_unlimited(): void
    {
        config()->set('esign.cpanel.min_memory_bytes', 256 * 1024 * 1024);
        config()->set('esign.cpanel.min_execution_seconds', 60);
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', '0');

        $result = $this->app->make(ResourceLimitsProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_when_memory_limit_is_below_the_minimum(): void
    {
        config()->set('esign.cpanel.min_memory_bytes', 512 * 1024 * 1024);
        config()->set('esign.cpanel.min_execution_seconds', 60);
        ini_set('memory_limit', '128M');
        ini_set('max_execution_time', '120');

        $result = $this->app->make(ResourceLimitsProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_fails_when_max_execution_time_is_below_the_minimum(): void
    {
        config()->set('esign.cpanel.min_memory_bytes', 64 * 1024 * 1024);
        config()->set('esign.cpanel.min_execution_seconds', 300);
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '30');

        $result = $this->app->make(ResourceLimitsProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
