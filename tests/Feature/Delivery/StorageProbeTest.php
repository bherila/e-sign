<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\StorageProbe;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageProbeTest extends TestCase
{
    public function test_is_ok_when_the_default_disk_round_trips(): void
    {
        Storage::fake(config('filesystems.default'));

        $result = $this->app->make(StorageProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_leaves_no_probe_file_behind(): void
    {
        Storage::fake(config('filesystems.default'));

        $this->app->make(StorageProbe::class)->check();

        $this->assertCount(0, Storage::disk(config('filesystems.default'))->allFiles('health-checks'));
    }
}
