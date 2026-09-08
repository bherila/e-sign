<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\QueueProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_ok_with_an_empty_queue(): void
    {
        $result = $this->app->make(QueueProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_warns_when_the_oldest_job_exceeds_the_warn_threshold(): void
    {
        $this->insertJob(now()->subSeconds(150)->getTimestamp());

        $result = $this->app->make(QueueProbe::class)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
    }

    public function test_fails_when_the_oldest_job_exceeds_the_fail_threshold(): void
    {
        $this->insertJob(now()->subSeconds(700)->getTimestamp());

        $result = $this->app->make(QueueProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_warns_when_jobs_failed_in_the_last_24_hours(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $result = $this->app->make(QueueProbe::class)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
    }

    private function insertJob(int $availableAt): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }
}
