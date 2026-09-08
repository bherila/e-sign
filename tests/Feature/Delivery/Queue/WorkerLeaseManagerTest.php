<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Queue;

use App\Domain\Delivery\Queue\WorkerLeaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkerLeaseManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquire_succeeds_when_no_lease_row_exists(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $now = CarbonImmutable::now();

        $this->assertTrue($leases->acquire('esign-queue-worker', 'holder-a', $now, 900));

        $this->assertDatabaseHas('worker_leases', [
            'name' => 'esign-queue-worker',
            'holder_token' => 'holder-a',
        ]);
    }

    public function test_acquire_refuses_while_a_live_lease_is_held(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $now = CarbonImmutable::now();

        $this->assertTrue($leases->acquire('esign-queue-worker', 'holder-a', $now, 900));
        $this->assertFalse($leases->acquire('esign-queue-worker', 'holder-b', $now, 900));

        // The original holder's row is untouched.
        $this->assertDatabaseHas('worker_leases', [
            'name' => 'esign-queue-worker',
            'holder_token' => 'holder-a',
        ]);
        $this->assertDatabaseCount('worker_leases', 1);
    }

    public function test_acquire_takes_over_a_stale_lease(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $longAgo = CarbonImmutable::now()->subHour();

        // A lease acquired an hour ago with a short TTL is long expired.
        $this->assertTrue($leases->acquire('esign-queue-worker', 'holder-a', $longAgo, 60));

        $now = CarbonImmutable::now();
        $this->assertTrue($leases->acquire('esign-queue-worker', 'holder-b', $now, 900));

        $this->assertDatabaseHas('worker_leases', [
            'name' => 'esign-queue-worker',
            'holder_token' => 'holder-b',
        ]);
        $this->assertDatabaseCount('worker_leases', 1);
    }

    public function test_heartbeat_extends_expiry_for_the_current_holder(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $now = CarbonImmutable::now();

        $leases->acquire('esign-queue-worker', 'holder-a', $now, 60);

        $later = $now->addSeconds(30);
        $this->assertTrue($leases->heartbeat('esign-queue-worker', 'holder-a', $later, 60));

        $row = DB::table('worker_leases')->where('name', 'esign-queue-worker')->sole();
        $this->assertSame(
            $later->addSeconds(60)->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($row->expires_at)->format('Y-m-d H:i:s'),
        );
    }

    public function test_heartbeat_fails_once_another_holder_has_taken_over(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $longAgo = CarbonImmutable::now()->subHour();

        $leases->acquire('esign-queue-worker', 'holder-a', $longAgo, 60);

        $now = CarbonImmutable::now();
        $this->assertTrue($leases->acquire('esign-queue-worker', 'holder-b', $now, 900));

        // holder-a no longer owns the row; its heartbeat must report that honestly.
        $this->assertFalse($leases->heartbeat('esign-queue-worker', 'holder-a', $now, 60));
    }

    public function test_release_deletes_the_row_for_the_current_holder(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $now = CarbonImmutable::now();

        $leases->acquire('esign-queue-worker', 'holder-a', $now, 900);

        $this->assertTrue($leases->release('esign-queue-worker', 'holder-a'));
        $this->assertDatabaseCount('worker_leases', 0);

        // Released, so a fresh acquire succeeds immediately without needing to wait out a TTL.
        $this->assertTrue($leases->acquire('esign-queue-worker', 'holder-c', CarbonImmutable::now(), 900));
    }

    public function test_release_is_a_no_op_for_a_holder_that_no_longer_owns_the_lease(): void
    {
        $leases = app(WorkerLeaseManager::class);
        $longAgo = CarbonImmutable::now()->subHour();

        $leases->acquire('esign-queue-worker', 'holder-a', $longAgo, 60);
        $leases->acquire('esign-queue-worker', 'holder-b', CarbonImmutable::now(), 900);

        $this->assertFalse($leases->release('esign-queue-worker', 'holder-a'));

        // holder-b's lease survives holder-a's (stale) release attempt.
        $this->assertDatabaseHas('worker_leases', [
            'name' => 'esign-queue-worker',
            'holder_token' => 'holder-b',
        ]);
    }
}
