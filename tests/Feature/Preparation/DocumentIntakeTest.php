<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\DocumentStorageException;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DocumentWorkspace;
use Tests\Support\PdfBombFixtures;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * Document intake: what is stored, what is refused, and what is recorded.
 *
 * The fixture matrix from Stage 0 (docs/stage0/pdf-import.md) drives the rejection cases,
 * so every hazard class the preflight parser classifies has an intake test that proves the
 * upload is refused rather than silently prepared.
 */
class DocumentIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->workspace = Workspace::factory()->create();
        $this->sender = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Sender);
    }

    protected function tearDown(): void
    {
        DocumentWorkspace::cleanUp();

        parent::tearDown();
    }

    public function test_an_accepted_upload_is_stored_with_an_original_and_a_review_revision(): void
    {
        $document = $this->intake('single-page-letter');

        $this->assertSame(DocumentStatus::Ready, $document->status);
        $this->assertSame(1, $document->page_count);
        $this->assertSame('application/pdf', $document->original_mime);
        $this->assertSame($this->sender->getKey(), $document->uploaded_by);
        $this->assertSame($this->workspace->getKey(), $document->workspace_id);

        $expected = hash('sha256', PdfFixtures::bytes('single-page-letter'));
        $this->assertSame($expected, $document->original_sha256);
        $this->assertSame(strlen(PdfFixtures::bytes('single-page-letter')), $document->original_bytes);

        $this->assertNotNull($document->originalRevision());
        $this->assertNotNull($document->reviewRevision());
        $this->assertCount(2, $document->revisions()->get());
    }

    public function test_the_stored_original_is_byte_for_byte_what_was_uploaded(): void
    {
        $bytes = PdfFixtures::bytes('multi-page-mixed-size');
        $document = $this->intake('multi-page-mixed-size');

        $readBack = Storage::disk('documents')->get($document->original_path);

        $this->assertSame($bytes, $readBack);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', (string) $readBack));
        $this->assertSame($document->original_sha256, hash('sha256', (string) $readBack));
    }

    public function test_the_storage_key_is_content_addressed_and_scoped_to_the_workspace(): void
    {
        $document = $this->intake('single-page-letter');
        $review = $document->reviewRevision();

        $this->assertNotNull($review);
        $this->assertSame(
            sprintf(
                'documents/%s/%s/original-%s.pdf',
                $this->workspace->public_id,
                $document->public_id,
                $document->original_sha256,
            ),
            $document->original_path,
        );
        $this->assertSame(
            sprintf(
                'documents/%s/%s/review-%s.pdf',
                $this->workspace->public_id,
                $document->public_id,
                $review->sha256,
            ),
            $review->path,
        );
    }

    public function test_without_a_normalization_step_the_review_revision_is_the_original_bytes(): void
    {
        $document = $this->intake('single-page-letter');
        $review = $document->reviewRevision();

        $this->assertNotNull($review);
        $this->assertSame($document->original_sha256, $review->sha256);
        $this->assertSame($document->original_bytes, $review->bytes);
        $this->assertFalse($review->normalization['applied']);
        $this->assertSame([], $review->normalization['steps']);
        $this->assertStringContainsString('byte for byte', $review->normalization['summary']);
        $this->assertSame(
            PdfFixtures::bytes('single-page-letter'),
            Storage::disk('documents')->get($review->path),
        );
    }

    public function test_a_configured_rebuild_produces_a_different_review_digest_and_discloses_what_it_costs(): void
    {
        config()->set('esign.documents.normalization.rebuild_pages', true);

        $document = $this->intake('form-fields-annotations');
        $review = $document->reviewRevision();

        $this->assertNotNull($review);
        $this->assertNotSame($document->original_sha256, $review->sha256);
        $this->assertTrue($review->normalization['applied']);
        $this->assertSame(['rebuild_pages'], $review->normalization['steps']);
        $this->assertSame($document->original_sha256, $review->normalization['source_sha256']);

        $disclosures = implode("\n", $review->normalization['disclosures']);
        $this->assertStringContainsString('annotations are not carried', $disclosures);
        $this->assertStringContainsString('/AcroForm', $disclosures);

        // The original is untouched by the rebuild, which is the whole point of keeping both.
        $this->assertSame(
            PdfFixtures::bytes('form-fields-annotations'),
            Storage::disk('documents')->get($document->original_path),
        );
    }

    /**
     * Every hazard class in the Stage 0 matrix, with the actionable phrase the sender is
     * told to act on.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rejectedFixtures(): array
    {
        return [
            'encrypted' => ['encrypted-aes128', 'encrypted', 'Remove the password'],
            'already signed' => ['already-signed', 'already_signed', 'Upload the unsigned original'],
            'javascript' => ['javascript-action', 'javascript', 'Remove the scripting'],
            'xfa' => ['xfa-form', 'xfa', 'Flatten the form'],
            'embedded file' => ['embedded-file', 'embedded_file', 'Remove them'],
        ];
    }

    #[DataProvider('rejectedFixtures')]
    public function test_a_hazardous_upload_is_rejected_with_an_actionable_message(
        string $fixture,
        string $code,
        string $phrase,
    ): void {
        $document = $this->intake($fixture);

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertNull($document->reviewRevision(), 'A rejected document must never gain a review revision.');
        $this->assertNull($document->page_count);

        $codes = array_column(
            array_filter(
                $document->preflight_report['findings'],
                static fn (array $finding): bool => $finding['severity'] === 'reject',
            ),
            'code',
        );
        $this->assertContains($code, $codes);

        $messages = implode("\n", array_column($document->preflight_report['findings'], 'message'));
        $this->assertStringContainsString($phrase, $messages);
    }

    public function test_a_file_that_is_not_a_pdf_at_all_is_rejected_as_unparseable(): void
    {
        $document = app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::uploadOfBytes('this is not a PDF at all', 'notes.pdf'),
        );

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertFalse($document->preflight_report['accepted']);
        $this->assertSame(
            ['unparseable'],
            array_values(array_unique(array_column(
                array_filter(
                    $document->preflight_report['findings'],
                    static fn (array $f): bool => $f['severity'] === 'reject',
                ),
                'code',
            ))),
        );
    }

    public function test_a_rejected_upload_keeps_its_original_bytes_for_forensics(): void
    {
        $document = $this->intake('javascript-action');
        $original = $document->originalRevision();

        $this->assertNotNull($original, 'The original revision is the record of what was uploaded.');
        $this->assertSame(RevisionKind::Original, $original->kind);
        $this->assertTrue(Storage::disk('documents')->exists($document->original_path));
        $this->assertSame(
            PdfFixtures::bytes('javascript-action'),
            Storage::disk('documents')->get($document->original_path),
        );
    }

    public function test_the_configured_size_limit_is_enforced_by_the_parser_not_only_by_the_form_request(): void
    {
        config()->set('esign.documents.max_bytes', 128);

        $document = $this->intake('single-page-letter');

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertStringContainsString(
            'the limit is 128 bytes',
            implode("\n", array_column($document->preflight_report['findings'], 'message')),
        );
    }

    public function test_the_configured_page_limit_is_enforced(): void
    {
        config()->set('esign.documents.max_pages', 2);

        $document = $this->intake('multi-page-mixed-size');

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertStringContainsString(
            '2-page limit',
            implode("\n", array_column($document->preflight_report['findings'], 'message')),
        );
    }

    public function test_an_upload_records_an_audit_event_naming_the_digest_and_the_actor(): void
    {
        $document = $this->intake('single-page-letter');

        $event = AuditEvent::query()->where('action', 'preparation.document_uploaded')->sole();

        $this->assertSame('user', $event->actor_type);
        $this->assertSame((string) $this->sender->getKey(), $event->actor_id);
        $this->assertSame(Document::class, $event->subject_type);
        $this->assertSame((string) $document->getKey(), $event->subject_id);
        $this->assertSame($document->original_sha256, $event->payload['original_sha256']);
        $this->assertFalse($event->payload['normalization_applied']);
    }

    public function test_a_rejected_upload_records_a_rejection_audit_event(): void
    {
        $this->intake('xfa-form');

        $event = AuditEvent::query()->where('action', 'preparation.document_rejected')->sole();

        $this->assertSame(['xfa'], $event->payload['rejection_codes']);
        $this->assertNull($event->payload['review_sha256']);
    }

    public function test_a_storage_failure_leaves_no_document_rows_at_all(): void
    {
        config()->set('filesystems.disks.unwritable', [
            'driver' => 'local',
            // A path under a non-directory, so the driver cannot create it.
            'root' => __FILE__.'/not-a-directory',
            'throw' => true,
        ]);
        config()->set('esign.documents.disk', 'unwritable');

        $this->expectException(DocumentStorageException::class);

        try {
            $this->intake('single-page-letter');
        } finally {
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('document_revisions', 0);
        }
    }

    public function test_a_failure_inside_the_transaction_rolls_back_every_row(): void
    {
        // The audit write is the last thing intake does inside its transaction. Making it
        // fail proves the document row and both revision rows go with it, rather than
        // leaving a document nobody can explain the provenance of.
        $this->app->bind(AuditRecorder::class, fn (): object => new class extends AuditRecorder
        {
            public function record(
                AuditActor $actor,
                string $action,
                ?Model $subject = null,
                array $payload = [],
            ): AuditEvent {
                throw new RuntimeException('the audit store is down');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the audit store is down');

        try {
            $this->intake('single-page-letter');
        } finally {
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('document_revisions', 0);

            // The bytes did reach storage: intake writes and verifies them before it opens
            // the transaction. They are now unreferenced, which is exactly the condition the
            // orphan pruner in docs/BLOB_STORAGE.md exists to reclaim, and is preferable to
            // a row that points at bytes which never arrived.
            $this->assertNotEmpty(Storage::disk('documents')->allFiles());
        }
    }

    public function test_a_revision_row_cannot_be_updated_or_deleted(): void
    {
        $document = $this->intake('single-page-letter');
        $review = $document->reviewRevision();
        $this->assertNotNull($review);

        try {
            $review->update(['sha256' => str_repeat('0', 64)]);
            $this->fail('A revision must refuse an update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $review->delete();
            $this->fail('A revision must refuse a delete.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function test_the_title_falls_back_to_the_uploaded_filename_without_its_extension(): void
    {
        $document = app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::upload('single-page-letter', 'Mutual NDA 2026.pdf'),
        );

        $this->assertSame('Mutual NDA 2026', $document->title);
    }

    /**
     * Issue #88 / review U-1: a decompression bomb never becomes a preparable document.
     *
     * The original bytes are still kept — that is the deliberate policy in
     * docs/preparation/documents.md, and a hostile upload is precisely the file an operator
     * needs to be able to examine. What must not exist is anything downstream of preflight:
     * no review revision, no `ready` status, no normalized copy. Nothing that was refused is
     * ever shown for assent.
     */
    public function test_a_decompression_bomb_is_refused_before_anything_downstream_of_preflight_runs(): void
    {
        config()->set('esign.documents.max_decompressed_bytes', 4 * 1_048_576);
        config()->set('esign.documents.max_decoded_stream_bytes', 2 * 1_048_576);

        $document = $this->bombIntake('many-small-streams-bomb');

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertNull($document->page_count);
        $this->assertNull($document->reviewRevision(), 'A refused bomb must never gain a review revision.');
        $this->assertCount(1, $document->revisions()->get(), 'Only the original is recorded.');
        $this->assertCount(
            1,
            Storage::disk('documents')->allFiles(),
            'Only the original bytes are written; nothing normalized a document preflight refused.',
        );

        $this->assertSame(
            ['decompression_limit_exceeded'],
            array_values(array_unique(array_column(
                array_filter(
                    $document->preflight_report['findings'],
                    static fn (array $f): bool => $f['severity'] === 'reject',
                ),
                'code',
            ))),
        );
    }

    public function test_the_configured_aggregate_decompression_ceiling_is_enforced(): void
    {
        config()->set('esign.documents.max_decompressed_bytes', 4_096);

        $document = $this->bombIntake('large-legitimate-control');

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertStringContainsString(
            '4096 bytes',
            implode("\n", array_column($document->preflight_report['findings'], 'message')),
        );
    }

    public function test_the_configured_object_ceiling_is_enforced_while_the_document_is_read(): void
    {
        config()->set('esign.documents.max_objects', 1_000);

        $document = $this->bombIntake('object-count-bomb');

        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);
        $this->assertStringContainsString(
            'more than 1000 indirect objects',
            implode("\n", array_column($document->preflight_report['findings'], 'message')),
        );
        $this->assertLessThan(100, $document->preflight_report['metrics']['object_count']);
    }

    public function test_a_large_but_legitimate_document_passes_on_the_shipped_defaults(): void
    {
        $document = $this->bombIntake('large-legitimate-control');

        $this->assertSame(DocumentStatus::Ready, $document->status);
        $this->assertSame(40, $document->page_count);
    }

    /**
     * The numbers docs/preparation/documents.md publishes are the numbers that ship.
     *
     * PdfPreflightLimitsTest proves the mechanism at a scale that is fast to test; this is
     * what pins the defaults themselves, so lowering the aggregate budget below the
     * per-stream ceiling — which would make the per-stream one unreachable — cannot happen
     * silently.
     */
    public function test_the_shipped_ceilings_are_the_documented_ones(): void
    {
        $this->assertSame(33_554_432, config('esign.documents.max_bytes'));
        $this->assertSame(33_554_432, config('esign.documents.max_decoded_stream_bytes'));
        $this->assertSame(268_435_456, config('esign.documents.max_decompressed_bytes'));
        $this->assertSame(100_000, config('esign.documents.max_objects'));
        $this->assertSame(30.0, config('esign.documents.preflight_time_budget_seconds'));
        $this->assertSame(268_435_456, config('esign.documents.preflight_memory_budget_bytes'));

        $this->assertSame(
            8,
            intdiv(
                (int) config('esign.documents.max_decompressed_bytes'),
                (int) config('esign.documents.max_bytes'),
            ),
            'The aggregate budget is 8x the upload ceiling; see the reasoning in config/esign.php.',
        );
    }

    private function bombIntake(string $fixture): Document
    {
        return app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::uploadOfBytes(PdfBombFixtures::bytes($fixture), $fixture.'.pdf'),
        );
    }

    private function intake(string $fixture): Document
    {
        return app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::upload($fixture),
        );
    }
}
