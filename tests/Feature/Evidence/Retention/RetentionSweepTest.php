<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence\Retention;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Retention\RetentionPolicy;
use App\Domain\Evidence\Retention\RetentionSweeper;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RetentionScenario;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The three policies, and the two properties that matter more than any of them: a dry run
 * changes nothing, and an unconfigured executed-document policy is not a zero-day one.
 */
class RetentionSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    // ---------------------------------------------------------------------------------
    // Dry run
    // ---------------------------------------------------------------------------------

    public function test_a_dry_run_reports_everything_and_deletes_nothing(): void
    {
        $scenario = SigningScenario::create()->bind();
        $draft = $scenario->draft();
        RetentionScenario::backdate('envelopes', (int) $draft->getKey(), [
            'created_at' => RetentionScenario::daysAgo(200),
        ]);

        $document = RetentionScenario::orphanDocument($scenario->workspace, 200);
        $objects = RetentionScenario::objectsOf($document);
        $this->seedAuthLogRows(3, 500);

        $this->artisan('esign:retention:run', ['--dry-run' => true])
            ->expectsOutputToContain('draft '.$draft->public_id)
            ->expectsOutputToContain('document '.$document->public_id)
            ->expectsOutputToContain('Dry run. Nothing was deleted.')
            ->assertSuccessful();

        $this->assertDatabaseHas('envelopes', ['id' => $draft->getKey()]);
        $this->assertDatabaseHas('documents', ['id' => $document->getKey()]);
        $this->assertSame(3, DB::table('auth_audit_log')->count());

        foreach ($objects as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' was removed by a dry run.');
        }
    }

    // ---------------------------------------------------------------------------------
    // Executed documents: never automatic
    // ---------------------------------------------------------------------------------

    public function test_an_unset_executed_policy_never_touches_an_executed_envelope(): void
    {
        config(['esign.retention.executed_documents_days' => null]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        RetentionScenario::completedDaysAgo($envelope, 10_000);

        $sweeper = app(RetentionSweeper::class);
        $policy = RetentionPolicy::fromConfig(config());
        $plan = $sweeper->plan($policy);

        $this->assertFalse($plan->executedPolicyConfigured);
        $this->assertSame([], $plan->executedEnvelopes);

        $sweeper->apply($plan, $policy, AuditActor::console('test'));

        $this->assertNull(Envelope::query()->whereKey($envelope->getKey())->sole()->deleted_at);
        $this->assertSame(3, Artifact::query()->count());
    }

    /**
     * An empty string is what a half-finished `.env` edit leaves behind, and it must not
     * read as "delete everything completed before now".
     */
    public function test_an_empty_or_zero_executed_policy_is_treated_as_unset(): void
    {
        foreach (['', '0', 0, -5, 'nonsense', null] as $value) {
            $policy = RetentionPolicy::fromArray(['executed_documents_days' => $value]);

            $this->assertFalse($policy->deletesExecutedDocuments(), var_export($value, true).' enabled deletion.');
            $this->assertNull($policy->executedDocumentsCutoff(RetentionScenario::daysAgo(0)));
        }
    }

    public function test_a_configured_policy_soft_deletes_the_envelope_and_records_every_digest(): void
    {
        config(['esign.retention.executed_documents_days' => 365]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        RetentionScenario::completedDaysAgo($envelope, 400);

        $digests = Artifact::query()->pluck('sha256')->all();
        $this->assertCount(3, $digests);

        $sweeper = app(RetentionSweeper::class);
        $policy = RetentionPolicy::fromConfig(config());
        $outcome = $sweeper->apply($sweeper->plan($policy), $policy, AuditActor::console('esign:retention:run'));

        $this->assertSame(1, $outcome->executedEnvelopesSoftDeleted);

        // Soft, not hard: the row is still there, and hidden from every ordinary query.
        $this->assertNull(Envelope::query()->whereKey($envelope->getKey())->first());
        $this->assertNotNull(Envelope::query()->withTrashed()->whereKey($envelope->getKey())->sole()->deleted_at);

        // The artifacts rows are immutable and survive; the bytes are still there until the
        // grace period passes and the second pass runs.
        $this->assertSame(3, Artifact::query()->count());

        foreach (Artifact::query()->get() as $artifact) {
            $this->assertTrue(Storage::disk('documents')->exists($artifact->path));
        }

        $event = AuditEvent::query()->where('action', RetentionSweeper::EXECUTED_DELETED)->sole();

        $this->assertSame($envelope->public_id, $event->payload['envelope']);
        $this->assertSame(3, $event->payload['artifact_count']);
        $this->assertEqualsCanonicalizing(
            $digests,
            array_column($event->payload['artifact_digests'], 'sha256'),
        );
    }

    public function test_an_executed_envelope_inside_the_window_is_left_alone(): void
    {
        config(['esign.retention.executed_documents_days' => 365]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        RetentionScenario::completedDaysAgo($envelope, 100);

        $sweeper = app(RetentionSweeper::class);
        $policy = RetentionPolicy::fromConfig(config());
        $sweeper->apply($sweeper->plan($policy), $policy, AuditActor::console('test'));

        $this->assertNotNull(Envelope::query()->whereKey($envelope->getKey())->first());
    }

    // ---------------------------------------------------------------------------------
    // Abandoned drafts and unreferenced documents
    // ---------------------------------------------------------------------------------

    public function test_an_old_draft_that_was_never_sent_is_deleted_with_its_recipients_and_values(): void
    {
        $scenario = SigningScenario::create()->bind();
        $draft = $scenario->preparedDraft();
        RetentionScenario::backdate('envelopes', (int) $draft->getKey(), [
            'created_at' => RetentionScenario::daysAgo(200),
        ]);

        $this->assertGreaterThan(0, DB::table('envelope_recipients')->where('envelope_id', $draft->getKey())->count());

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('envelopes', ['id' => $draft->getKey()]);
        $this->assertSame(0, DB::table('envelope_recipients')->where('envelope_id', $draft->getKey())->count());
        $this->assertSame(0, DB::table('envelope_field_values')->where('envelope_id', $draft->getKey())->count());
    }

    public function test_a_recent_draft_and_a_sent_envelope_are_never_swept(): void
    {
        $scenario = SigningScenario::create()->bind();
        $recent = $scenario->draft();
        $sent = $scenario->sent();

        // Old enough by date, but it was sent, so it is history rather than an abandoned draft.
        RetentionScenario::backdate('envelopes', (int) $sent->getKey(), [
            'created_at' => RetentionScenario::daysAgo(500),
        ]);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('envelopes', ['id' => $recent->getKey()]);
        $this->assertDatabaseHas('envelopes', ['id' => $sent->getKey()]);
    }

    public function test_an_unreferenced_document_loses_its_rows_and_its_bytes(): void
    {
        $scenario = SigningScenario::create()->bind();
        $document = RetentionScenario::orphanDocument($scenario->workspace, 200);
        $objects = RetentionScenario::objectsOf($document);

        $this->assertCount(2, $objects);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('documents', ['id' => $document->getKey()]);
        $this->assertSame(0, DB::table('document_revisions')->where('document_id', $document->getKey())->count());

        foreach ($objects as $path) {
            $this->assertFalse(Storage::disk('documents')->exists($path), $path.' survived the sweep.');
        }

        $event = AuditEvent::query()->where('action', RetentionSweeper::ABANDONED_DELETED)->sole();
        $this->assertSame(1, $event->payload['documents']);
        $this->assertSame(2, $event->payload['objects_deleted']);
    }

    public function test_a_document_an_envelope_still_references_is_never_swept(): void
    {
        $scenario = SigningScenario::create()->bind();
        $scenario->sent();

        // The scenario's document is old, but its review revision is what the envelope was
        // built from. Set membership, not age.
        RetentionScenario::backdate('documents', (int) $scenario->document->getKey(), [
            'created_at' => RetentionScenario::daysAgo(900),
        ]);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('documents', ['id' => $scenario->document->getKey()]);
    }

    /**
     * A soft-deleted envelope is still a reference. Destroying the revision it was built
     * from during the grace period would make an undo impossible.
     */
    public function test_a_soft_deleted_envelope_still_protects_the_document_it_was_built_from(): void
    {
        config(['esign.retention.executed_documents_days' => 365]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $document = $scenario->signing->document;

        $envelope->delete();
        RetentionScenario::backdate('documents', (int) $document->getKey(), [
            'created_at' => RetentionScenario::daysAgo(900),
        ]);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('documents', ['id' => $document->getKey()]);
        $this->assertTrue(Storage::disk('documents')->exists($scenario->signing->revision->path));
    }

    // ---------------------------------------------------------------------------------
    // Authentication log
    // ---------------------------------------------------------------------------------

    public function test_old_authentication_rows_go_and_recent_ones_stay(): void
    {
        $this->seedAuthLogRows(4, 500);
        $this->seedAuthLogRows(2, 10);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertSame(2, DB::table('auth_audit_log')->count());

        $event = AuditEvent::query()->where('action', RetentionSweeper::AUTH_LOGS_DELETED)->sole();
        $this->assertSame(4, $event->payload['rows']);
        $this->assertSame(400, $event->payload['retained_days']);
    }

    public function test_the_authentication_window_is_configurable(): void
    {
        config(['esign.retention.auth_logs_days' => 5]);

        $this->seedAuthLogRows(3, 10);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('auth_audit_log')->count());
    }

    private function seedAuthLogRows(int $count, int $ageInDays): void
    {
        $at = RetentionScenario::daysAgo($ageInDays)->toDateTimeString();

        for ($i = 0; $i < $count; $i++) {
            DB::table('auth_audit_log')->insert([
                'event' => 'login',
                'succeeded' => true,
                'email' => 'synthetic'.$i.'@example.invalid',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }
}
