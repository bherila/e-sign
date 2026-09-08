<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence\Retention;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Retention\BlobPurger;
use App\Domain\Evidence\Retention\Exceptions\LegalHoldActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\LegalHold;
use App\Domain\Evidence\Retention\RecipientEraser;
use App\Domain\Evidence\Retention\RetentionPolicy;
use App\Domain\Evidence\Retention\RetentionSweeper;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RetentionScenario;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * A legal hold is only worth the paths that consult it, so this asserts every one of them.
 *
 * The interesting failure is not "the hold column was not set". It is a deletion path added
 * later that nobody remembered to teach about holds, which is why each pass gets its own
 * test rather than one test of the service in isolation.
 */
class LegalHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_a_hold_records_the_reason_the_actor_and_an_audit_event(): void
    {
        $envelope = $this->draft();

        app(LegalHold::class)->place(
            $envelope,
            'Preservation notice 2026-14',
            AuditActor::console('esign:hold:place'),
        );

        $envelope->refresh();

        $this->assertTrue($envelope->isUnderLegalHold());
        $this->assertSame('Preservation notice 2026-14', $envelope->legal_hold_reason);
        $this->assertSame('esign:hold:place', $envelope->legal_hold_by);

        $event = AuditEvent::query()->where('action', LegalHold::PLACED)->sole();

        $this->assertSame('cli', $event->actor_type);
        $this->assertSame($envelope->public_id, $event->payload['envelope']);
        $this->assertSame('Preservation notice 2026-14', $event->payload['reason']);
    }

    public function test_a_hold_needs_a_reason_and_cannot_be_placed_twice(): void
    {
        $envelope = $this->draft();
        $holds = app(LegalHold::class);

        $this->expectException(RetentionRefused::class);
        $holds->place($envelope, '   ', AuditActor::console('esign:hold:place'));
    }

    public function test_placing_a_second_hold_is_refused_so_the_original_date_survives(): void
    {
        $envelope = $this->draft();
        $holds = app(LegalHold::class);

        $holds->place($envelope, 'First notice', AuditActor::console('esign:hold:place'));
        $placedAt = $envelope->refresh()->legal_hold_at;

        try {
            $holds->place($envelope, 'Second notice', AuditActor::console('esign:hold:place'));
            $this->fail('A second hold should be refused.');
        } catch (RetentionRefused $refusal) {
            $this->assertStringContainsString('has been under legal hold since', $refusal->getMessage());
        }

        $this->assertEquals($placedAt, $envelope->refresh()->legal_hold_at);
        $this->assertSame('First notice', $envelope->legal_hold_reason);
    }

    public function test_releasing_copies_the_cleared_columns_into_the_audit_event(): void
    {
        $envelope = $this->draft();
        $holds = app(LegalHold::class);

        $holds->place($envelope, 'Preservation notice 2026-14', AuditActor::console('esign:hold:place'));
        $holds->release($envelope, 'Matter closed', AuditActor::console('esign:hold:release'));

        $envelope->refresh();

        $this->assertFalse($envelope->isUnderLegalHold());
        $this->assertNull($envelope->legal_hold_reason);
        $this->assertNull($envelope->legal_hold_by);

        $event = AuditEvent::query()->where('action', LegalHold::RELEASED)->sole();

        // The row has forgotten it was ever held. This event is the only remaining record.
        $this->assertSame('Preservation notice 2026-14', $event->payload['held_reason']);
        $this->assertSame('Matter closed', $event->payload['reason']);
        $this->assertNotNull($event->payload['held_since']);
    }

    public function test_releasing_a_hold_that_is_not_there_is_refused(): void
    {
        $this->expectException(RetentionRefused::class);

        app(LegalHold::class)->release($this->draft(), 'Nothing to do', AuditActor::console('esign:hold:release'));
    }

    // ---------------------------------------------------------------------------------
    // Every deletion path refuses a held envelope
    // ---------------------------------------------------------------------------------

    public function test_a_hold_excludes_an_abandoned_draft_from_the_sweep_and_the_exclusion_is_reported(): void
    {
        Storage::fake('documents');

        $envelope = $this->draft();
        RetentionScenario::backdate('envelopes', (int) $envelope->getKey(), [
            'created_at' => RetentionScenario::daysAgo(200),
        ]);

        app(LegalHold::class)->place($envelope, 'Held', AuditActor::console('esign:hold:place'));

        $sweeper = app(RetentionSweeper::class);
        $plan = $sweeper->plan(RetentionPolicy::fromConfig(config()));

        $this->assertSame([], $plan->abandonedEnvelopes);
        $this->assertSame(1, $plan->heldEnvelopesExcluded);

        $sweeper->apply($plan, RetentionPolicy::fromConfig(config()), AuditActor::console('test'));

        $this->assertDatabaseHas('envelopes', ['id' => $envelope->getKey()]);
    }

    public function test_a_hold_excludes_an_executed_envelope_even_when_the_policy_is_configured(): void
    {
        config(['esign.retention.executed_documents_days' => 30]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        RetentionScenario::completedDaysAgo($envelope, 400);

        app(LegalHold::class)->place($envelope, 'Dispute pending', AuditActor::console('esign:hold:place'));

        $sweeper = app(RetentionSweeper::class);
        $policy = RetentionPolicy::fromConfig(config());
        $plan = $sweeper->plan($policy);

        $this->assertSame([], $plan->executedEnvelopes);
        $this->assertSame(1, $plan->heldEnvelopesExcluded);

        $sweeper->apply($plan, $policy, AuditActor::console('test'));

        $this->assertNull(Envelope::query()->whereKey($envelope->getKey())->first()?->deleted_at);
    }

    public function test_a_hold_placed_during_the_grace_period_stops_the_blob_purge(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $paths = Artifact::query()->pluck('path')->all();

        $envelope->delete();
        RetentionScenario::softDeletedDaysAgo($envelope, 90);

        // The preservation notice arrives after the soft delete and before the purge. That
        // window is the entire reason the two passes are separate.
        app(LegalHold::class)->place(
            Envelope::query()->withTrashed()->whereKey($envelope->getKey())->sole(),
            'Notice arrived during the grace period',
            AuditActor::console('esign:hold:place'),
        );

        $purger = app(BlobPurger::class);
        $policy = RetentionPolicy::fromConfig(config());
        $result = $purger->purge($purger->plan($policy), AuditActor::console('test'));

        $this->assertSame(0, $result['purged']);
        $this->assertSame(1, $result['held_skipped']);

        foreach ($paths as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' was purged despite a hold.');
        }
    }

    public function test_a_hold_refuses_a_privacy_erasure(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $recipient = $envelope->recipients()->firstOrFail();
        $email = $recipient->email;

        app(LegalHold::class)->place($envelope, 'Held', AuditActor::console('esign:hold:place'));

        try {
            app(RecipientEraser::class)->erase($recipient, 'Erasure request', AuditActor::console('test'));
            $this->fail('An erasure on a held envelope should be refused.');
        } catch (LegalHoldActive $refusal) {
            $this->assertStringContainsString($envelope->public_id, $refusal->getMessage());
        }

        $this->assertSame($email, $recipient->refresh()->email);
    }

    /**
     * One instance, one held envelope, one soft-deleted document, and every path in the
     * application that can remove a row or an object, run one after another.
     *
     * The per-path tests above each prove one exclusion. This proves the property the
     * runbook actually claims — *"no routine path removes a held agreement"*, and *"a
     * soft-deleted row still counts"* — by walking the whole set in one pass, so a pass
     * added later that consults neither is a failure here rather than a gap nobody notices.
     * Issue #89.
     */
    public function test_no_deletion_path_removes_a_held_envelope_or_a_soft_deleted_document(): void
    {
        config([
            // Every window as short as it can be, so nothing survives by being too young.
            'esign.retention.executed_documents_days' => 30,
            'esign.retention.abandoned_drafts_days' => 30,
            'esign.retention.purge_grace_days' => 30,
        ]);

        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $recipient = $envelope->recipients()->firstOrFail();
        $address = $recipient->email;
        $artifacts = Artifact::query()->get();

        $this->assertCount(3, $artifacts);

        // Held and old enough for the executed-agreement pass.
        RetentionScenario::completedDaysAgo($envelope, 400);

        // Held and old enough for the abandoned-draft pass.
        $draft = $scenario->signing->preparedDraft();
        RetentionScenario::backdate('envelopes', (int) $draft->getKey(), [
            'created_at' => RetentionScenario::daysAgo(400),
        ]);

        $recipients = DB::table('envelope_recipients')->where('envelope_id', $draft->getKey())->count();
        $this->assertGreaterThan(0, $recipients);

        // Old enough for the unreferenced-document pass, and soft-deleted, which is an undo
        // rather than a decision to destroy.
        $document = RetentionScenario::orphanDocument($scenario->signing->workspace, 400);
        $objects = RetentionScenario::objectsOf($document);
        $document->delete();

        $holds = app(LegalHold::class);
        $holds->place($envelope, 'Preservation notice 2026-14', AuditActor::console('esign:hold:place'));
        $holds->place($draft, 'Preservation notice 2026-14', AuditActor::console('esign:hold:place'));

        // Paths 1, 2, and 3: the abandoned-draft, unreferenced-document, and
        // executed-agreement passes. The fourth pass in this command deletes
        // `auth_audit_log` rows, which belong to no envelope and cannot be soft-deleted, so
        // there is nothing for it to consult and nothing here for it to spare.
        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        // Path 4: privacy erasure, which a hold refuses outright.
        try {
            app(RecipientEraser::class)->erase($recipient, 'Article 17 request', AuditActor::console('test'));
            $this->fail('A hold should refuse an erasure.');
        } catch (LegalHoldActive) {
            // Expected. The assertions below are what the refusal has to have preserved.
        }

        // Path 5: the staging pruner, the one byte-deleting path outside this module.
        $this->artisan('esign:artifacts:prune-staging', ['--apply' => true])->assertSuccessful();

        // Path 6: the blob purge, with the envelope soft-deleted for longer than the grace
        // period so that it is genuinely eligible and only the hold is stopping it.
        DB::table('envelopes')->where('id', $envelope->getKey())->update([
            'deleted_at' => RetentionScenario::daysAgo(90)->toDateTimeString(),
        ]);

        $this->artisan('esign:retention:purge-blobs', ['--force' => true])->assertSuccessful();

        // ---- Nothing was removed, by any of them. ----

        $this->assertSame(1, Envelope::query()->withTrashed()->whereKey($envelope->getKey())->count());
        $this->assertSame(1, Envelope::query()->whereKey($draft->getKey())->count());
        $this->assertNull($draft->refresh()->deleted_at);
        $this->assertSame($recipients, DB::table('envelope_recipients')->where('envelope_id', $draft->getKey())->count());

        $this->assertSame(3, Artifact::query()->count());

        foreach ($artifacts as $artifact) {
            $this->assertTrue(
                Storage::disk('documents')->exists($artifact->path),
                $artifact->kind->value.' was removed from a held envelope.',
            );
        }

        $this->assertSame(1, Document::query()->withTrashed()->whereKey($document->getKey())->count());
        $this->assertSame(2, DB::table('document_revisions')->where('document_id', $document->getKey())->count());

        foreach ($objects as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' was destroyed by a soft delete.');
        }

        // The document the held agreement was built from, and the bytes behind it.
        $this->assertSame(1, Document::query()->whereKey($scenario->signing->document->getKey())->count());
        $this->assertTrue(Storage::disk('documents')->exists($scenario->signing->revision->path));

        // Contact data intact: the erasure was refused, not partially applied.
        $this->assertSame($address, $recipient->refresh()->email);

        // And no path wrote a deletion event, which is the other half of "nothing happened":
        // a sweep that deleted nothing and a sweep that deleted something without saying so
        // look the same from the row counts alone.
        foreach ([
            RetentionSweeper::EXECUTED_DELETED,
            RetentionSweeper::ABANDONED_DELETED,
            BlobPurger::PURGED,
            RecipientEraser::ERASED,
        ] as $action) {
            $this->assertSame(0, AuditEvent::query()->where('action', $action)->count(), $action.' was recorded.');
        }
    }

    public function test_the_console_commands_place_and_release_a_hold(): void
    {
        $envelope = $this->draft();

        $this->artisan('esign:hold:place', [
            'envelope' => $envelope->public_id,
            '--reason' => 'Preservation notice 2026-14',
        ])->assertSuccessful();

        $this->assertTrue($envelope->refresh()->isUnderLegalHold());

        $this->artisan('esign:hold:release', [
            'envelope' => $envelope->public_id,
            '--reason' => 'Matter closed',
        ])->assertSuccessful();

        $this->assertFalse($envelope->refresh()->isUnderLegalHold());
    }

    public function test_the_place_command_refuses_an_unknown_envelope(): void
    {
        $this->artisan('esign:hold:place', ['envelope' => 'nope', '--reason' => 'x'])->assertFailed();
    }

    private function draft(): Envelope
    {
        return SigningScenario::create()->bind()->draft();
    }
}
