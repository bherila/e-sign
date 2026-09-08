<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Queue;

use App\Domain\Delivery\Queue\WorkerLeaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\RecordingQueueJob;
use Tests\TestCase;

/**
 * esign:queue:work-bounded end to end: the lease guard, and that it actually drains the
 * `database` queue connection rather than merely reporting success (docs/HANDOFF.md section 13,
 * issue #39).
 */
class WorkBoundedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_start_while_a_live_lease_is_held(): void
    {
        app(WorkerLeaseManager::class)->acquire('esign-queue-worker', 'another-process', CarbonImmutable::now(), 900);

        $this->artisan('esign:queue:work-bounded')
            ->expectsOutputToContain('already held by a live worker')
            ->assertSuccessful();

        // Untouched: this process never became the holder.
        $this->assertDatabaseHas('worker_leases', [
            'name' => 'esign-queue-worker',
            'holder_token' => 'another-process',
        ]);
    }

    public function test_it_processes_a_queued_database_job_and_releases_the_lease_on_completion(): void
    {
        config(['queue.default' => 'database']);

        $cacheKey = 'test-recording-job-'.Str::uuid();
        RecordingQueueJob::dispatch($cacheKey)->onConnection('database')->onQueue('default');

        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('esign:queue:work-bounded', [
            '--max-time' => 5,
            '--max-jobs' => 10,
            '--lease-ttl' => 60,
            '--heartbeat' => 1,
        ])->assertSuccessful();

        $this->assertTrue(Cache::get($cacheKey), 'the queued job should actually have run');
        $this->assertDatabaseCount('jobs', 0);

        // The lease is released on a clean exit, so a subsequent tick can acquire it immediately.
        $this->assertDatabaseCount('worker_leases', 0);
    }

    public function test_it_refuses_a_lease_ttl_that_does_not_exceed_max_time(): void
    {
        $this->artisan('esign:queue:work-bounded', [
            '--max-time' => 100,
            '--lease-ttl' => 50,
        ])
            ->expectsOutputToContain('must be greater than')
            ->assertFailed();

        // Never even attempted to acquire.
        $this->assertDatabaseCount('worker_leases', 0);
    }
}
