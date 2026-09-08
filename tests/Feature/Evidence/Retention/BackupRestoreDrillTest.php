<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence\Retention;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailOutbox;
use App\Domain\Delivery\Mail\MailRecipient;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Evidence\Retention\BackupManifest;
use App\Domain\Evidence\Retention\Exceptions\RestoreDrillActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\RestoreDrill;
use App\Domain\Identity\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RetentionScenario;
use Tests\Support\SyntheticMailContext;
use Tests\TestCase;

/**
 * The backup manifest, and the two things a restore drill has to guarantee: that it is
 * running against a copy, and that the copy cannot contact anybody.
 */
class BackupRestoreDrillTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------------------
    // Manifest round trip
    // ---------------------------------------------------------------------------------

    public function test_a_manifest_round_trips_and_matches_the_database_it_was_taken_from(): void
    {
        RetentionScenario::finalized();

        $path = $this->manifestPath();

        $this->artisan('esign:backup:manifest', ['--path' => $path])
            ->expectsOutputToContain('3 published artifact(s)')
            ->assertSuccessful();

        $manifests = app(BackupManifest::class);
        $manifest = $manifests->read($path);

        $this->assertSame(BackupManifest::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertSame(1, $manifest['tables']['envelopes']);
        $this->assertSame(3, $manifest['artifacts']['count']);
        $this->assertSame($manifests->artifactTotals()['digest_total'], $manifest['artifacts']['digest_total']);
        $this->assertSame([], $manifests->differencesFrom($manifest));
    }

    public function test_a_row_that_did_not_restore_is_a_difference(): void
    {
        $scenario = RetentionScenario::finalized();
        $manifests = app(BackupManifest::class);
        $manifest = $manifests->build();

        // A table that came back one row short is the failure a count comparison exists for.
        Workspace::query()->whereKey($scenario->signing->workspace->getKey())->update(['name' => 'renamed']);
        Workspace::factory()->create();

        $differences = $manifests->differencesFrom($manifest);

        $this->assertNotEmpty($differences);
        $this->assertStringContainsString('workspaces', $differences[0]);
    }

    public function test_an_artifact_whose_digest_changed_is_a_difference_even_when_the_counts_match(): void
    {
        RetentionScenario::finalized();
        $manifests = app(BackupManifest::class);
        $manifest = $manifests->build();

        $manifest['artifacts']['digest_total'] = str_repeat('0', 64);

        $differences = $manifests->differencesFrom($manifest);

        $this->assertNotEmpty($differences);
        $this->assertStringContainsString('artifact digest total', implode(' ', $differences));
    }

    public function test_volatile_tables_are_counted_but_never_compared(): void
    {
        $manifests = app(BackupManifest::class);
        $manifest = $manifests->build();

        $this->assertArrayHasKey('sessions', $manifest['tables']);
        $this->assertContains('sessions', $manifest['volatile_tables']);

        $manifest['tables']['sessions'] = 999;

        $this->assertSame([], $manifests->differencesFrom($manifest));
    }

    public function test_a_missing_or_wrong_version_manifest_is_refused(): void
    {
        $manifests = app(BackupManifest::class);
        $path = $this->manifestPath();

        try {
            $manifests->read($path);
            $this->fail('A missing manifest should be refused.');
        } catch (RetentionRefused $refusal) {
            $this->assertStringContainsString('No readable backup manifest', $refusal->getMessage());
        }

        file_put_contents($path, json_encode(['schema_version' => 99, 'tables' => []]));

        $this->expectException(RetentionRefused::class);
        $manifests->read($path);
    }

    // ---------------------------------------------------------------------------------
    // The restore guard
    // ---------------------------------------------------------------------------------

    public function test_the_restore_drill_refuses_without_the_environment_variable(): void
    {
        config(['esign.restore_drill' => false]);

        $this->artisan('esign:restore:verify')
            ->expectsOutputToContain('ESIGN_RESTORE_DRILL=1')
            ->assertFailed();
    }

    public function test_the_restore_drill_refuses_in_production_even_with_the_variable_set(): void
    {
        config(['esign.restore_drill' => true]);
        $this->app['env'] = 'production';

        $this->artisan('esign:restore:verify')
            ->expectsOutputToContain('APP_ENV=production')
            ->assertFailed();
    }

    public function test_the_restore_drill_verifies_a_matching_restore(): void
    {
        RetentionScenario::finalized();

        $path = $this->manifestPath();
        app(BackupManifest::class)->write($path, app(BackupManifest::class)->build());

        config(['esign.restore_drill' => true]);

        $this->artisan('esign:restore:verify', ['--manifest' => $path])
            ->expectsOutputToContain('refusing to send')
            ->expectsOutputToContain('Restore verified')
            ->assertSuccessful();
    }

    public function test_the_restore_drill_fails_when_the_artifacts_did_not_come_back(): void
    {
        RetentionScenario::finalized();

        $path = $this->manifestPath();
        app(BackupManifest::class)->write($path, app(BackupManifest::class)->build());

        // The database restored perfectly and the object store did not. This is the failure
        // the manifest comparison alone cannot see.
        foreach (Storage::disk('documents')->allFiles('envelopes') as $object) {
            Storage::disk('documents')->delete($object);
        }

        config(['esign.restore_drill' => true]);

        $this->artisan('esign:restore:verify', ['--manifest' => $path])
            ->expectsOutputToContain('not present on its disk')
            ->expectsOutputToContain('not verified')
            ->assertFailed();
    }

    public function test_the_restore_drill_fails_when_the_database_is_short_of_rows(): void
    {
        RetentionScenario::finalized();

        $path = $this->manifestPath();
        $manifest = app(BackupManifest::class)->build();
        $manifest['tables']['envelopes'] = 7;
        app(BackupManifest::class)->write($path, $manifest);

        config(['esign.restore_drill' => true]);

        $this->artisan('esign:restore:verify', ['--manifest' => $path])
            ->expectsOutputToContain('the manifest recorded 7')
            ->assertFailed();
    }

    // ---------------------------------------------------------------------------------
    // Suppression
    // ---------------------------------------------------------------------------------

    public function test_the_mail_outbox_refuses_to_queue_anything_during_a_drill(): void
    {
        config(['esign.restore_drill' => true]);

        $this->expectException(RestoreDrillActive::class);

        app(MailOutbox::class)->enqueue(
            MailKind::Invitation,
            new MailRecipient('signer@example.test', 'Avery Counterparty'),
            SyntheticMailContext::for(MailKind::Invitation),
        );
    }

    public function test_no_mail_row_is_written_when_the_drill_refuses(): void
    {
        config(['esign.restore_drill' => true]);

        try {
            app(MailOutbox::class)->enqueue(
                MailKind::Invitation,
                new MailRecipient('signer@example.test', 'Avery Counterparty'),
                SyntheticMailContext::for(MailKind::Invitation),
            );
        } catch (RestoreDrillActive) {
            // Expected.
        }

        $this->assertDatabaseCount('outbound_mails', 0);
    }

    public function test_the_webhook_dispatcher_refuses_to_queue_a_delivery_during_a_drill(): void
    {
        $workspace = Workspace::factory()->create();
        $endpoint = WebhookEndpoint::factory()->for($workspace)->create();

        $event = OutboxEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'event_name' => 'signing_request.completed',
            'payload' => ['synthetic' => true],
            'canonical_body' => '{"synthetic":true}',
            'occurred_at' => now(),
        ]);

        config(['esign.restore_drill' => true]);

        try {
            app(WebhookDispatcher::class)->fanOut($event->refresh());
            $this->fail('The dispatcher should refuse during a restore drill.');
        } catch (RestoreDrillActive $refusal) {
            $this->assertStringContainsString('ESIGN_RESTORE_DRILL', $refusal->getMessage());
        }

        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertTrue($endpoint->refresh()->isEnabled());
    }

    public function test_nothing_is_suppressed_when_the_variable_is_not_set(): void
    {
        config(['esign.restore_drill' => false]);

        $this->assertFalse(app(RestoreDrill::class)->isActive());

        $mail = app(MailOutbox::class)->enqueue(
            MailKind::Invitation,
            new MailRecipient('signer@example.test', 'Avery Counterparty'),
            SyntheticMailContext::for(MailKind::Invitation),
        );

        $this->assertDatabaseHas('outbound_mails', ['id' => $mail->getKey()]);
    }

    private function manifestPath(): string
    {
        $path = sys_get_temp_dir().'/esign-manifest-'.bin2hex(random_bytes(6)).'.json';

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }
}
