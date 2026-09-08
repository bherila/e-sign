<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\EvidenceDocument;
use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Evidence\Finalization\ExecutedDocumentRenderer;
use App\Domain\Evidence\Finalization\FinalizationRun;
use App\Domain\Evidence\Finalization\FinalizationRunState;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Exceptions\IllegalTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CmsVerification;
use Tests\Support\FinalizationScenario;
use Tests\Support\LoggingEnvelopeEventSink;
use Tests\Support\RecordingArtifactStore;
use Tests\TestCase;

/**
 * The staged publication, end to end and at every crash point.
 *
 * These are the release-gate tests for issue #28. Each fault-injection case pins one specific
 * way the process could lie: a completion with no document behind it, a document with no
 * completion, two documents for one agreement, or a cancelled agreement that got executed
 * anyway.
 */
class EnvelopeFinalizationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------------------

    public function test_it_publishes_three_artifacts_and_completes_the_envelope(): void
    {
        $scenario = FinalizationScenario::signed();

        $run = $scenario->finalizer()->finalize($scenario->envelope);

        $this->assertSame(FinalizationRunState::Published, $run->state);
        $this->assertSame(1, $run->generation);
        $this->assertNull($run->error);
        $this->assertNotNull($run->finished_at);

        $envelope = $scenario->envelope->refresh();
        $this->assertSame(EnvelopeState::Completed, $envelope->state);
        $this->assertNotNull($envelope->completed_at);

        $artifacts = Artifact::query()->where('envelope_id', $envelope->getKey())->get();
        $this->assertCount(3, $artifacts);

        $kinds = $artifacts->map(fn (Artifact $a): string => $a->kind->value)->sort()->values()->all();
        $this->assertSame(
            ['completion_report', 'evidence_json', 'executed_pdf'],
            $kinds,
        );

        foreach ($artifacts as $artifact) {
            $this->assertNotNull($artifact->published_at);
            $this->assertSame(1, $artifact->generation);
            $this->assertSame('documents', $artifact->disk);
            $this->assertStringStartsWith(
                'envelopes/'.$scenario->signing->workspace->public_id.'/'.$envelope->public_id.'/',
                $artifact->path,
            );
            // Content-addressed: the digest is in the key, so nothing can be overwritten.
            $this->assertStringContainsString($artifact->sha256, $artifact->path);
            $this->assertSame(
                $artifact->sha256,
                hash('sha256', Storage::disk('documents')->get($artifact->path)),
            );
        }

        // The envelope's artifact reference names the executed PDF, not a storage key.
        $executed = $artifacts->firstWhere('kind', ArtifactKind::ExecutedPdf);
        $this->assertSame($executed->public_id, $envelope->artifact_ref);
    }

    public function test_the_published_pdf_is_sealed_validated_and_verifiable_by_openssl(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $executed = $this->artifact($scenario, ArtifactKind::ExecutedPdf);
        $bytes = Storage::disk('documents')->get($executed->path);

        $report = app(ArtifactValidator::class)->validate($bytes);
        $this->assertTrue($report->isValid(), implode('; ', $report->failures));
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());
        $this->assertSame('ETSI.CAdES.detached', $report->subFilter);

        // A second, independent implementation of CMS over the same bytes.
        $signerPem = null;
        $this->assertTrue(CmsVerification::verifies($bytes, $signerPem));
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $signerPem);

        // The row records which key material sealed it (issue #29).
        $this->assertSame(AssuranceLevel::PadesBB, $executed->assurance_level_reached);
        $this->assertNotSame('', $executed->seal_key_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $executed->seal_certificate_sha256);
        $this->assertTrue($executed->validation_report['cryptographically_sound']);
    }

    public function test_the_executed_document_carries_the_field_values_and_the_completion_page(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $bytes = Storage::disk('documents')->get($this->artifact($scenario, ArtifactKind::ExecutedPdf)->path);

        // The source is three pages; the completion report adds one more.
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page[^s]/', $bytes));

        $report = Storage::disk('documents')->get($this->artifact($scenario, ArtifactKind::CompletionReport)->path);
        $text = $this->uncompressedText($report);

        $this->assertStringContainsString('Completion report', $text);
        $this->assertStringContainsString('not a certificate', $text);
        $this->assertStringContainsString('Example Buyer', $text);
        $this->assertStringContainsString('Example Seller', $text);
        $this->assertStringContainsString('pades-b-b', $text);
        $this->assertStringContainsString('fixture-seal-2026-a', $text);

        // Every acceptance digest is on the page.
        foreach ($scenario->envelope->attestations()->get() as $attestation) {
            $this->assertStringContainsString($attestation->attestation_sha256, $text);
        }

        // The report is the last page of the agreement, so a phrase unique to it is in both.
        $this->assertStringContainsString('Completion report', $this->uncompressedText($bytes));
    }

    public function test_the_evidence_document_digests_match_the_published_bytes(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $evidence = json_decode(
            Storage::disk('documents')->get($this->artifact($scenario, ArtifactKind::EvidenceJson)->path),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(EvidenceDocument::VERSION, $evidence['evidence_version']);
        $this->assertSame('esign.evidence.v1', $evidence['encoding']);

        $byKind = [];
        foreach ($evidence['artifacts'] as $entry) {
            $byKind[$entry['kind']] = $entry;
        }

        foreach ([ArtifactKind::ExecutedPdf, ArtifactKind::CompletionReport] as $kind) {
            $artifact = $this->artifact($scenario, $kind);
            $this->assertSame($artifact->sha256, $byKind[$kind->value]['sha256']);
            $this->assertSame($artifact->bytes, $byKind[$kind->value]['bytes']);
            $this->assertSame(
                $artifact->sha256,
                hash('sha256', Storage::disk('documents')->get($artifact->path)),
            );
        }

        // Every digest says what it covers, and the flat index repeats the whole set.
        $this->assertNotEmpty($evidence['digest_index']);
        foreach ($evidence['digest_index'] as $entry) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['value']);
            $this->assertNotSame('', trim($entry['covers']));
            $this->assertSame('sha256', $entry['algorithm']);
        }

        // The document digest is the reviewed revision, not the executed PDF.
        $this->assertSame($scenario->envelope->document_sha256, $evidence['document']['sha256']);
        $this->assertNotSame(
            $this->artifact($scenario, ArtifactKind::ExecutedPdf)->sha256,
            $evidence['document']['sha256'],
        );

        // Three timestamps, kept apart.
        $this->assertCount(2, $evidence['timestamps']['acceptance']);
        $this->assertNotSame('', $evidence['timestamps']['sealing']['sealed_at']);
        $this->assertNull($evidence['timestamps']['publication']);
        $this->assertStringContainsString('prediction', $evidence['timestamps']['publication_note']);

        // The attestation chain is complete and chained.
        $this->assertCount(2, $evidence['attestation_chain']);
        $this->assertNull($evidence['attestation_chain'][0]['prev_attestation_sha256']);
        $this->assertSame(
            $evidence['attestation_chain'][0]['attestation_sha256'],
            $evidence['attestation_chain'][1]['prev_attestation_sha256'],
        );

        // Minimized: the raw session reference is never exported.
        foreach ($evidence['attestation_chain'] as $entry) {
            $this->assertArrayNotHasKey('session_ref', $entry);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['session_ref_sha256']);
        }
    }

    public function test_the_input_snapshot_records_the_immutable_finalization_inputs(): void
    {
        $scenario = FinalizationScenario::signed();
        $run = $scenario->finalizer()->finalize($scenario->envelope);

        $snapshot = $run->input_snapshot;

        $this->assertSame($scenario->envelope->document_sha256, $snapshot['document_sha256']);
        $this->assertSame($scenario->envelope->field_schema_sha256, $snapshot['field_schema_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot['material_values_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot['snapshot_sha256']);
        $this->assertNotEmpty($snapshot['field_values']);
        $this->assertCount(2, $snapshot['attestations']);

        foreach ($snapshot['field_values'] as $value) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $value['value_sha256']);
            // Digests, never the value itself: a signature image must not be copied into an
            // operational table.
            $this->assertArrayNotHasKey('value', $value);
        }
    }

    // ---------------------------------------------------------------------------------
    // Fault injection
    // ---------------------------------------------------------------------------------

    /** A crash before anything is uploaded leaves a visibly failed envelope and no bytes. */
    public function test_a_failure_while_rendering_publishes_nothing(): void
    {
        $scenario = FinalizationScenario::signed();

        // The failure is injected at the engine, so the renderer under test is the real one.
        $renderer = new ExecutedDocumentRenderer(new class implements PdfAssembler
        {
            public function assemble(string $pdfBytes, array $overlays = [], array $appendedDocuments = []): AssembledDocument
            {
                throw new AssemblyException('Simulated worker failure while rendering.');
            }
        });

        $this->expectException(FinalizationException::class);

        try {
            $scenario->finalizer(renderer: $renderer)->finalize($scenario->envelope);
        } finally {
            $run = FinalizationRun::query()->firstOrFail();

            $this->assertSame(FinalizationRunState::Failed, $run->state);
            $this->assertNull($run->outputs);
            $this->assertNotNull($run->error);
            $this->assertNotNull($run->finished_at);

            $this->assertSame(EnvelopeState::FinalizationFailed, $scenario->envelope->refresh()->state);
            $this->assertSame(0, Artifact::query()->count());
            $this->assertSame([], Storage::disk('documents')->allFiles('envelopes'));
        }
    }

    /**
     * The crash-after-upload case, and the recovery from it.
     *
     * The bytes are durable and no row exists — the state a killed worker leaves. The retry
     * must publish *those* bytes rather than sealing a second document.
     */
    public function test_a_crash_after_upload_is_recovered_by_republishing_the_same_bytes(): void
    {
        $scenario = FinalizationScenario::signed();

        $throwing = new LoggingEnvelopeEventSink(throwOn: EnvelopeEvent::Completed);

        try {
            $scenario->finalizer(sink: $throwing)->finalize($scenario->envelope);
            $this->fail('The publishing transaction was expected to fail.');
        } catch (FinalizationException) {
            // expected
        }

        $crashed = FinalizationRun::query()->firstOrFail();
        $this->assertSame(FinalizationRunState::Failed, $crashed->state);
        $this->assertNotNull($crashed->outputs);
        $this->assertCount(3, $crashed->outputs);

        // Durable bytes, no rows: the staging state.
        $staged = Storage::disk('documents')->allFiles('envelopes');
        $this->assertCount(3, $staged);
        $this->assertSame(0, Artifact::query()->count());
        $this->assertSame(EnvelopeState::FinalizationFailed, $scenario->envelope->refresh()->state);

        // Retry: put it back in the queue, then dispatch again.
        $scenario->machine()->retryFinalization($scenario->envelope->refresh());

        $log = [];
        $store = new RecordingArtifactStore(app(ArtifactStore::class), $log);
        $run = $scenario->finalizer(store: $store)->finalize($scenario->envelope->refresh());

        $this->assertSame(FinalizationRunState::Published, $run->state);
        $this->assertSame(2, $run->generation);

        // Nothing was written a second time; the recovery only re-verified.
        $this->assertSame([], array_values(array_filter(
            $store->log,
            static fn (string $entry): bool => str_starts_with($entry, 'stored:'),
        )));

        // The same three objects, and exactly three.
        $this->assertSame($staged, Storage::disk('documents')->allFiles('envelopes'));
        $this->assertSame(3, Artifact::query()->count());

        foreach ($crashed->outputs as $output) {
            $this->assertDatabaseHas('artifacts', [
                'path' => $output['path'],
                'sha256' => $output['sha256'],
                'generation' => 2,
            ]);
        }

        $this->assertSame(EnvelopeState::Completed, $scenario->envelope->refresh()->state);
    }

    /** Two attempts in flight; the compare-and-swap lets exactly one publish. */
    public function test_two_concurrent_attempts_publish_exactly_once(): void
    {
        $scenario = FinalizationScenario::signed();

        $secondRun = null;
        $log = [];

        // The interleaving is real: the callback runs while the first attempt is between its
        // two transactions and holds no lock, which is the only window a race has.
        $store = new RecordingArtifactStore(
            app(ArtifactStore::class),
            $log,
            function () use ($scenario, &$secondRun): void {
                $secondRun = $scenario->finalizer()->finalize($scenario->envelope->fresh());
            },
            afterPut: 1,
        );

        $first = $scenario->finalizer(store: $store)->finalize($scenario->envelope);

        $this->assertNotNull($secondRun);
        $this->assertSame(FinalizationRunState::Published, $secondRun->state);
        $this->assertSame(2, $secondRun->generation);

        // The first attempt lost the race and says so, without touching the envelope.
        $this->assertSame(FinalizationRunState::Failed, $first->state);
        $this->assertSame(1, $first->generation);
        $this->assertStringContainsString('completed', (string) $first->error);

        $this->assertSame(EnvelopeState::Completed, $scenario->envelope->refresh()->state);
        $this->assertSame(3, Artifact::query()->count());
        $this->assertSame(
            [2],
            Artifact::query()->distinct()->pluck('generation')->all(),
        );
    }

    /**
     * A withdrawal racing a finalization can never produce an executed agreement.
     *
     * The race is closed at both ends, and this pins both. `cancel` is not a legal transition
     * from `finalizing` at all, so a sender's withdrawal that arrives mid-attempt is refused
     * by the state machine rather than silently applied to a document that is already being
     * sealed. And if the envelope leaves `finalizing` by the one route that *is* legal, the
     * publishing transaction's compare-and-swap refuses to publish into it.
     */
    public function test_an_envelope_that_leaves_finalizing_mid_attempt_is_never_published(): void
    {
        $scenario = FinalizationScenario::signed();
        $log = [];
        $refusedCancel = null;

        $store = new RecordingArtifactStore(
            app(ArtifactStore::class),
            $log,
            function () use ($scenario, &$refusedCancel): void {
                try {
                    $scenario->machine()->cancel($scenario->envelope->fresh(), 'Withdrawn by the sender.');
                } catch (IllegalTransition $exception) {
                    $refusedCancel = $exception;
                }

                $scenario->machine()->markFinalizationFailed(
                    $scenario->envelope->fresh(),
                    'Withdrawn while finalizing.',
                );
            },
            afterPut: 1,
        );

        $run = $scenario->finalizer(store: $store)->finalize($scenario->envelope);

        $this->assertInstanceOf(IllegalTransition::class, $refusedCancel);
        $this->assertSame(FinalizationRunState::Failed, $run->state);
        $this->assertStringContainsString('finalization_failed', (string) $run->error);
        $this->assertSame(EnvelopeState::FinalizationFailed, $scenario->envelope->refresh()->state);
        $this->assertSame(0, Artifact::query()->count());
        $this->assertNull($scenario->envelope->refresh()->artifact_ref);
    }

    /** A requested level this deployment cannot reach is an error, never a quiet downgrade. */
    public function test_b_t_without_a_timestamp_authority_fails_closed(): void
    {
        $scenario = FinalizationScenario::signed(AssuranceLevel::PadesBT);

        try {
            $scenario->finalizer()->finalize($scenario->envelope);
            $this->fail('Finalization was expected to refuse PAdES B-T with no timestamp authority.');
        } catch (FinalizationException $exception) {
            $this->assertStringContainsString('timestamp', strtolower($exception->getMessage()));
        }

        $this->assertSame(EnvelopeState::FinalizationFailed, $scenario->envelope->refresh()->state);
        $this->assertSame(0, Artifact::query()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles('envelopes'));

        $run = FinalizationRun::query()->firstOrFail();
        $this->assertSame(FinalizationRunState::Failed, $run->state);
        $this->assertNull($run->outputs);
    }

    public function test_finalizing_an_envelope_in_another_state_records_no_attempt(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $this->expectException(FinalizationException::class);

        try {
            $scenario->finalizer()->finalize($scenario->envelope->refresh());
        } finally {
            $this->assertSame(1, FinalizationRun::query()->count());
            $this->assertSame(3, Artifact::query()->count());
        }
    }

    // ---------------------------------------------------------------------------------
    // Ordering
    // ---------------------------------------------------------------------------------

    /**
     * The completion event never precedes proof that the bytes are retrievable.
     *
     * Asserted as a sequence rather than as an end state, because an implementation that
     * completed first and stored afterwards would leave exactly the same rows behind.
     */
    public function test_completion_is_published_only_after_every_artifact_reads_back(): void
    {
        $scenario = FinalizationScenario::signed();

        $log = [];
        $store = new RecordingArtifactStore(app(ArtifactStore::class), $log);
        $sink = new LoggingEnvelopeEventSink($log);

        $scenario->finalizer(store: $store, sink: $sink)->finalize($scenario->envelope);

        $completedAt = array_search('event:'.EnvelopeEvent::Completed->value, $log, true);
        $this->assertIsInt($completedAt, 'The completion event was never published.');

        $readBacks = array_keys(array_filter(
            $log,
            static fn (string $entry): bool => str_starts_with($entry, 'read-back:'),
        ));
        $stores = array_keys(array_filter(
            $log,
            static fn (string $entry): bool => str_starts_with($entry, 'stored:'),
        ));

        $this->assertCount(3, $stores);
        $this->assertCount(3, $readBacks);
        $this->assertLessThan($completedAt, max($readBacks));
        $this->assertLessThan($completedAt, max($stores));
    }

    // ---------------------------------------------------------------------------------
    // Digest hygiene
    // ---------------------------------------------------------------------------------

    /**
     * An object store's ETag is never the application's document hash.
     *
     * docs/HANDOFF.md section 12 says so explicitly, and the failure mode is quiet: an ETag is
     * a transfer checksum whose algorithm depends on how the object was uploaded, so code that
     * used one would keep working until somebody switched to multipart uploads.
     */
    public function test_no_application_code_uses_an_etag_as_a_digest(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Comments and docblocks are stripped first: this file's own explanation of why
            // an ETag is not a document hash must not be what makes the check fail.
            $contents = php_strip_whitespace($file->getPathname());

            if (preg_match('/etag/i', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'ETags must never appear in application code.');
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    private function artifact(FinalizationScenario $scenario, ArtifactKind $kind): Artifact
    {
        return Artifact::query()
            ->where('envelope_id', $scenario->envelope->getKey())
            ->where('kind', $kind->value)
            ->firstOrFail();
    }

    /** Page text, with any Flate-compressed content streams inflated. */
    private function uncompressedText(string $pdf): string
    {
        $text = $pdf;
        $matches = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) !== false) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream);

                if (is_string($inflated)) {
                    $text .= "\n".$inflated;
                }
            }
        }

        return $text;
    }
}
