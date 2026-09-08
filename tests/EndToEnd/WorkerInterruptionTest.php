<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Finalization\FinalizationRun;
use App\Domain\Evidence\Finalization\FinalizationRunState;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixtures;
use Tests\Support\RecordingArtifactStore;
use Tests\Support\SyntheticConsumer\ObservingEnvelopeEventSink;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\Support\SyntheticConsumer\WorkerInterrupted;
use Tests\TestCase;
use Throwable;

/**
 * A worker killed between the upload and the publication, and what the consumer sees.
 *
 * `docs/HANDOFF.md` §13 asks a deployment to "recover after worker interruption", and §14's
 * artifact-durability gate asks that a crash "after upload" never emits an invalid completion
 * or loses the selected final bytes. `tests/Feature/Evidence/EnvelopeFinalizationTest.php`
 * proves the recovery at the domain level, with a recording sink that never reaches an outbox.
 * What is left, and what this file is, is the consumer's half: after the crash and the retry,
 * does the receiver hold **one** `signing_request.completed`, and is the artifact behind it the
 * one that was uploaded before the crash?
 *
 * Two events would be worse than none. A receiver that files an executed agreement twice has
 * two of them, and "deduplicate on the event id" does not help when the ids differ because
 * they really are two events.
 */
class WorkerInterruptionTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNER = 'mira@recovery.example.test';

    public function test_a_worker_killed_before_publication_recovers_with_one_artifact_and_one_completion(): void
    {
        $consumer = SyntheticConsumer::install($this);
        $id = $this->signedEnvelope($consumer);

        /* ------------------------------------------------------------------- the crash */

        $crashLog = [];
        $crashStore = new RecordingArtifactStore(app(ArtifactStore::class), $crashLog);

        // Throws on the completion event, which is raised *inside* the publishing
        // transaction: the artifact rows are inserted and rolled back, the outbox row is never
        // written, and the objects uploaded in the previous step stay exactly where they are.
        $interrupted = new ObservingEnvelopeEventSink(
            app(EnvelopeEventSink::class),
            $crashLog,
            EnvelopeEvent::Completed,
        );

        try {
            $consumer->runFinalizationWorker($id, $consumer->finalizerWith($crashStore, $interrupted));
            $this->fail('The publishing transaction was expected to fail.');
        } catch (Throwable $failure) {
            $this->assertTrue(
                $failure instanceof WorkerInterrupted || $failure->getPrevious() instanceof WorkerInterrupted,
                'The run failed for a reason other than the arranged interruption: '.$failure->getMessage(),
            );
        }

        // Durable bytes, no rows: the state a killed process leaves behind.
        $staged = Storage::disk('documents')->allFiles('envelopes');
        $this->assertCount(3, $staged);
        $this->assertSame(0, Artifact::query()->count());
        $this->assertSame(EnvelopeState::FinalizationFailed, $consumer->envelope($id)->state);

        $crashed = FinalizationRun::query()->sole();
        $this->assertSame(FinalizationRunState::Failed, $crashed->state);
        $this->assertCount(3, (array) $crashed->outputs);

        // The bytes were uploaded and read back, the completion was attempted and interrupted,
        // and the only thing after it is the failure being recorded — which is a different
        // event, with a name of ours, and never a completion.
        $this->assertContains('interrupted:signing_request.completed', $crashLog);
        $this->assertNotContains('event:signing_request.completed', $crashLog);

        $this->assertLessThan(
            array_search('event:esign.envelope.finalization.failed', $crashLog, true),
            array_search('interrupted:signing_request.completed', $crashLog, true),
        );

        /* ------------------------------------------- what the consumer learns from it */

        $consumer->drainWebhooks();

        // Not a completion, and visibly a failure. `esign.envelope.finalization.failed` is
        // ours and namespaced, because the profile has no concept of one and inventing a
        // `signing_request.*` name for it would put a string on the wire nobody handles.
        $this->assertSame(0, $consumer->receiver->countProcessed('signing_request.completed'));
        $this->assertSame(1, $consumer->receiver->countProcessed('esign.envelope.finalization.failed'));
        $this->assertFalse($consumer->poll($id)['status']['finished']);

        // And no `download_url` is offered for an agreement that was never executed.
        $this->assertNull($consumer->poll($id)['final_document_download_url']);

        /* ------------------------------------------------------------------ the retry */

        $consumer->retryFinalization($id);

        $retryLog = [];
        $retryStore = new RecordingArtifactStore(app(ArtifactStore::class), $retryLog);

        $consumer->runFinalizationWorker($id, $consumer->finalizerWith($retryStore));
        $consumer->drainWebhooks();

        // Nothing was sealed or written a second time: the retry republished the bytes that
        // were already there, which is the whole point of leaving them.
        $this->assertSame([], array_values(array_filter(
            $retryLog,
            static fn (string $entry): bool => str_starts_with($entry, 'stored:'),
        )));
        $this->assertSame($staged, Storage::disk('documents')->allFiles('envelopes'));

        /* -------------------------------------------------------- one of everything */

        $this->assertSame(3, Artifact::query()->count());
        $this->assertSame(EnvelopeState::Completed, $consumer->envelope($id)->state);

        foreach ((array) $crashed->outputs as $output) {
            $this->assertDatabaseHas('artifacts', [
                'path' => $output['path'],
                'sha256' => $output['sha256'],
                'generation' => 2,
            ]);
        }

        // Exactly one logical completion event, and the consumer processed it exactly once.
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'signing_request.completed')->count());
        $this->assertSame(1, $consumer->receiver->countProcessed('signing_request.completed'));
        $this->assertCount(1, $consumer->receiver->attempts('signing_request.completed'));

        // And the agreement the consumer can now fetch is the one whose bytes survived.
        $executed = Artifact::query()
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $download = $consumer->download($id);
        $this->assertFalse($download['is_partial']);
        $this->assertSame(
            $executed->sha256,
            hash('sha256', $consumer->fetch($download['download_url'])->assertOk()->streamedContent()),
        );
    }

    /**
     * The ordering itself: the completion event is recorded after the bytes were read back.
     *
     * A test of the end state cannot tell a correct implementation from one that completes
     * first and stores afterwards, because both end up stored and complete. Sharing one log
     * between the artifact store and the event sink makes the sequence assertable.
     */
    public function test_the_completion_event_is_recorded_after_the_bytes_are_read_back(): void
    {
        $consumer = SyntheticConsumer::install($this);
        $id = $this->signedEnvelope($consumer);

        $log = [];
        $store = new RecordingArtifactStore(app(ArtifactStore::class), $log);
        $sink = new ObservingEnvelopeEventSink(app(EnvelopeEventSink::class), $log);

        $consumer->runFinalizationWorker($id, $consumer->finalizerWith($store, $sink));

        $completedAt = array_search('event:signing_request.completed', $log, true);
        $this->assertIsInt($completedAt, 'The completion event never reached the sink.');

        $readBacks = array_keys(array_filter(
            $log,
            static fn (string $entry): bool => str_starts_with($entry, 'read-back:'),
        ));

        $this->assertCount(3, $readBacks);

        foreach ($readBacks as $index) {
            $this->assertLessThan(
                $completedAt,
                $index,
                'A completion was announced before the bytes it describes were retrievable.',
            );
        }

        // And the consumer only hears about it afterwards, which is later still.
        $consumer->drainWebhooks();
        $this->assertSame(1, $consumer->receiver->countProcessed('signing_request.completed'));
    }

    /** A single-signer agreement sitting in `finalizing`, signed over HTTP. */
    private function signedEnvelope(SyntheticConsumer $consumer): string
    {
        $id = (string) $consumer->createAndSend([
            'name' => 'Synthetic worker-recovery agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Mira', 'last_name' => 'Signer', 'email' => self::SIGNER, 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Signature', 'recipient_email' => self::SIGNER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 30.0, 'height' => 5.0]],
            ],
        ])->assertStatus(201)->json('id');

        $consumer->signAs($id, self::SIGNER);
        $consumer->drainWebhooks();

        $this->assertSame(EnvelopeState::Finalizing, $consumer->envelope($id)->state);

        return $id;
    }
}
