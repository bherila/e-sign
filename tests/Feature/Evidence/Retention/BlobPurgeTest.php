<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence\Retention;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Retention\BlobPurger;
use App\Domain\Evidence\Retention\RetentionPolicy;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RetentionScenario;
use Tests\TestCase;

/**
 * The second pass, and the grace period that makes the first one reversible.
 */
class BlobPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bytes_inside_the_grace_period_are_not_removed(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $paths = Artifact::query()->pluck('path')->all();

        $envelope->delete();
        RetentionScenario::softDeletedDaysAgo($envelope, 10);

        $this->artisan('esign:retention:purge-blobs', ['--force' => true])->assertSuccessful();

        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' went inside the grace period.');
        }
    }

    public function test_bytes_past_the_grace_period_are_removed_and_the_digests_are_recorded(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $artifacts = Artifact::query()->get();

        $envelope->delete();
        RetentionScenario::softDeletedDaysAgo($envelope, 45);

        $this->artisan('esign:retention:purge-blobs', ['--force' => true])->assertSuccessful();

        foreach ($artifacts as $artifact) {
            $this->assertFalse(Storage::disk('documents')->exists($artifact->path), $artifact->path.' survived.');
        }

        // The evidence that there was an agreement survives the destruction of the document.
        $this->assertSame(3, Artifact::query()->count());

        $event = AuditEvent::query()->where('action', BlobPurger::PURGED)->sole();
        $this->assertSame(3, $event->payload['purged_count']);
        $this->assertEqualsCanonicalizing(
            $artifacts->pluck('sha256')->all(),
            array_column($event->payload['purged_digests'], 'sha256'),
        );
    }

    public function test_the_grace_period_is_configurable(): void
    {
        config(['esign.retention.purge_grace_days' => 5]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $path = Artifact::query()->value('path');

        $envelope->delete();
        RetentionScenario::softDeletedDaysAgo($envelope, 10);

        $this->artisan('esign:retention:purge-blobs', ['--force' => true])->assertSuccessful();

        $this->assertFalse(Storage::disk('documents')->exists((string) $path));
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $paths = Artifact::query()->pluck('path')->all();

        $envelope->delete();
        RetentionScenario::softDeletedDaysAgo($envelope, 45);

        $this->artisan('esign:retention:purge-blobs', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run. Nothing was removed.')
            ->assertSuccessful();

        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path));
        }
    }

    public function test_an_envelope_restored_during_the_grace_period_keeps_its_bytes(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $paths = Artifact::query()->pluck('path')->all();

        $envelope->delete();
        RetentionScenario::softDeletedDaysAgo($envelope, 45);

        $purger = app(BlobPurger::class);
        $planned = $purger->plan(RetentionPolicy::fromConfig(config()));

        // Somebody notices the policy was wrong and undoes it after the plan was computed.
        Envelope::query()->withTrashed()->whereKey($envelope->getKey())->sole()->restore();

        $result = $purger->purge($planned, AuditActor::console('test'));

        $this->assertSame(0, $result['purged']);

        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path));
        }
    }

    public function test_a_live_envelope_is_never_touched(): void
    {
        $scenario = RetentionScenario::finalized();
        $paths = Artifact::query()->pluck('path')->all();

        $this->artisan('esign:retention:purge-blobs', ['--force' => true])->assertSuccessful();

        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path));
        }

        $this->assertNotNull($scenario->envelope->refresh()->completed_at);
    }
}
