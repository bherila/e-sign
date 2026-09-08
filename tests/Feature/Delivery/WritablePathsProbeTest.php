<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\WritablePathsProbe;
use Tests\TestCase;

class WritablePathsProbeTest extends TestCase
{
    public function test_is_ok_when_storage_and_bootstrap_cache_are_writable(): void
    {
        $result = $this->app->make(WritablePathsProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_when_a_watched_path_does_not_exist(): void
    {
        // Simulate an unwritable/missing directory without touching the real filesystem the
        // rest of the suite depends on: bind a probe subclass is unnecessary here since the
        // probe reads storage_path()/base_path() directly, so instead point storage_path() at
        // a path that cannot exist by making bootstrap/cache's sibling check fail via a
        // non-writable temp directory substituted for one of the two paths.
        $missing = sys_get_temp_dir().'/esign-doctor-missing-'.uniqid();

        $this->app->useStoragePath($missing);

        $result = $this->app->make(WritablePathsProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
