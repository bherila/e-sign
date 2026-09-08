<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStore;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixtures;
use Tests\Support\SyntheticConsumer\InMemoryObjectStore;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\TestCase;

/**
 * The storage half of release gate 12: the backend is configuration, and the bytes do not
 * care which one it is.
 *
 * Two runs, two adapters. The first is the local driver every other test in this repository
 * uses; the second is {@see InMemoryObjectStore}, a flat key space with no directories, which
 * is the shape an S3-compatible backend has and the shape that breaks code quietly depending
 * on a real filesystem.
 *
 * **What is compared, and what is not.** The digests asserted identical here are the digests
 * *of the same artifact* read back through two different adapters. Two independent seals of the
 * same agreement are deliberately **not** compared: `tc-lib-pdf` writes a creation time and a
 * file identifier into every document it produces, so two runs of the same workflow yield two
 * different byte strings for reasons that have nothing to do with storage. Asserting they
 * matched would either fail or force this suite to freeze a clock the PDF engine does not
 * read. What matters — and what is asserted — is that the digest finalization recorded is the
 * digest the storage adapter returns, on both adapters, and that the retained original is
 * byte-identical on both.
 */
class StoragePortabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNER = 'ilya@portability.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        InMemoryObjectStore::forget();
        InMemoryObjectStore::register();
    }

    /**
     * The same published bytes, on two adapters, with the same digests.
     */
    public function test_the_published_artifacts_read_back_identically_on_a_second_backend(): void
    {
        $consumer = SyntheticConsumer::install($this);
        $id = $this->completedEnvelope($consumer);

        $artifacts = Artifact::query()
            ->where('envelope_id', $consumer->envelope($id)->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $artifacts);
        $this->assertSame('local', config('filesystems.disks.documents.driver'));

        $store = app(ArtifactStore::class);

        /** @var array<string, array{bytes: string, sha256: string}> $onLocal */
        $onLocal = [];

        foreach ($artifacts as $artifact) {
            $bytes = $store->get('documents', $artifact->path);

            // The recorded digest is the digest of the bytes that are actually there.
            $this->assertSame($artifact->sha256, hash('sha256', $bytes));
            $this->assertSame($artifact->sha256, $store->digestOf('documents', $artifact->path));

            $onLocal[$artifact->path] = [
                'bytes' => $bytes,
                'sha256' => $artifact->sha256,
                'key' => ArtifactStorageKey::for(
                    $consumer->scenario->workspace->public_id,
                    $id,
                    $artifact->kind,
                    $artifact->sha256,
                ),
            ];
        }

        /* ---------------------------------------------------- and now the object store */

        $consumer->useDocumentsDisk(InMemoryObjectStore::disk('esign-e2e-publish'));
        $this->assertSame(InMemoryObjectStore::DRIVER, config('filesystems.disks.documents.driver'));

        foreach ($onLocal as $path => $recorded) {
            // Re-published through the real store, which reads the object back and compares
            // digests before it returns — so a backend that mangled a byte would raise here
            // rather than be caught by the assertion below.
            // The key comes from the same factory publication used, so it is the same key.
            $this->assertSame($path, $recorded['key']->value);

            $store->putVerified('documents', $recorded['key'], $recorded['bytes'], $recorded['sha256']);
        }

        foreach ($onLocal as $path => $recorded) {
            $this->assertTrue($store->exists('documents', $path));
            $this->assertSame($recorded['sha256'], $store->digestOf('documents', $path));
            $this->assertSame($recorded['bytes'], $store->get('documents', $path));

            // And through a stream, which is how a download reads them.
            $stream = $store->readStream('documents', $path);
            $this->assertSame($recorded['bytes'], (string) stream_get_contents($stream));
            fclose($stream);
        }

        // A flat key space: the paths are keys, with no directory ever created for them.
        $keys = InMemoryObjectStore::keys('esign-e2e-publish');

        foreach (array_keys($onLocal) as $path) {
            $this->assertContains($path, $keys);
        }

        // The prefix listing the staging pruner relies on still finds them, and still reports
        // a timestamp per object.
        $listed = $store->listWithTimestamps('documents', 'envelopes');
        $this->assertNotEmpty($listed);

        foreach (array_keys($onLocal) as $path) {
            $this->assertArrayHasKey($path, $listed);
            $this->assertIsInt($listed[$path]);
        }
    }

    /**
     * The whole workflow, from creation to a validated download, on the object store.
     */
    public function test_a_complete_workflow_runs_on_an_object_store_backend(): void
    {
        $consumer = SyntheticConsumer::install($this);

        // Swapped before anything is created, so intake, sealing, publication and the download
        // all happen on the second backend.
        $consumer->useDocumentsDisk(InMemoryObjectStore::disk('esign-e2e-whole-run'));

        $id = $this->completedEnvelope($consumer);

        $completed = $consumer->receiver->firstProcessed('signing_request.completed');
        $this->assertNotNull($completed, 'The workflow did not complete on the object store.');
        $this->assertTrue($completed->reportsDownloadAvailable());

        $download = $consumer->download($id);
        $this->assertSame('finished', $download['status']);
        $this->assertFalse($download['is_partial']);

        // Streamed through the application on this driver too. Nothing is pre-signed and no
        // bucket or key appears in the link (docs/BLOB_STORAGE.md rule 1).
        $this->assertStringNotContainsString('esign-e2e-whole-run', $download['download_url']);

        $bytes = $consumer->fetch($download['download_url'])->assertOk()->streamedContent();

        $executed = Artifact::query()
            ->where('envelope_id', $consumer->envelope($id)->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $this->assertSame($executed->sha256, hash('sha256', $bytes));

        // Same verdict, same level, on the other backend.
        $report = app(ArtifactValidator::class)->validate($bytes);
        $this->assertSame([], $report->failures);
        $this->assertSame(AssuranceLevel::PadesBB, $report->reachedLevel());

        // Three artifacts, and their keys really are in the bucket rather than on a disk.
        $keys = InMemoryObjectStore::keys('esign-e2e-whole-run');
        $this->assertGreaterThanOrEqual(4, count($keys), 'The revision plus three artifacts.');
    }

    /**
     * The retained original is byte-for-byte the same on both backends.
     *
     * `AGENTS.md`: uploads and imported executed PDFs are never re-rendered, re-sealed, or
     * overwritten. That is a claim about bytes, so it is asserted on the bytes, across a
     * change of backend.
     */
    public function test_the_retained_original_is_byte_identical_across_backends(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $id = $this->sentEnvelope($consumer);
        $revision = $consumer->envelope($id)->documentRevision;

        $onLocal = Storage::disk('documents')->get($revision->path);
        $this->assertSame($revision->sha256, hash('sha256', $onLocal));

        $consumer->useDocumentsDisk(InMemoryObjectStore::disk('esign-e2e-original'));

        $onObjectStore = Storage::disk('documents')->get($revision->path);

        $this->assertSame($onLocal, $onObjectStore);
        $this->assertSame($revision->sha256, hash('sha256', $onObjectStore));

        // And the consumer's own read of it, over HTTP, agrees with the recorded digest.
        $streamed = $consumer->fetch($consumer->poll($id)['document_url'])->assertOk();

        $this->assertSame($revision->sha256, $streamed->headers->get('X-Document-Sha256'));
        $this->assertSame($revision->sha256, hash('sha256', $streamed->streamedContent()));
    }

    /* ------------------------------------------------------------------------ helpers */

    private function sentEnvelope(SyntheticConsumer $consumer): string
    {
        return (string) $consumer->createAndSend([
            'name' => 'Synthetic storage-portability agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Ilya', 'last_name' => 'Signer', 'email' => self::SIGNER, 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Signature', 'recipient_email' => self::SIGNER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 30.0, 'height' => 5.0]],
            ],
        ])->assertStatus(201)->json('id');
    }

    private function completedEnvelope(SyntheticConsumer $consumer): string
    {
        $id = $this->sentEnvelope($consumer);

        $consumer->signAs($id, self::SIGNER);
        $consumer->runFinalizationWorker($id);
        $consumer->drainWebhooks();

        return $id;
    }
}
