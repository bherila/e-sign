<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Finalization\ArtifactDownloader;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactStorageKey;
use App\Domain\Evidence\Finalization\EvidenceExporter;
use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationScenario;
use Tests\TestCase;
use ZipArchive;

/**
 * Getting published evidence back out: one artifact at a time, or the whole export.
 *
 * No routes are exercised here on purpose — there are none. These are the services the HTTP
 * surfaces call once their own authorization has run, and what is pinned is the part that must
 * not be re-decided per surface: streaming rather than presigning, a content type that comes
 * from the artifact's kind, a filename nothing hostile can influence, and an export whose
 * manifest actually describes the files in it.
 */
class EvidenceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_streams_an_artifact_with_a_fixed_content_type_and_a_safe_filename(): void
    {
        $scenario = $this->finalized();
        $artifact = $this->artifact($scenario, ArtifactKind::ExecutedPdf);

        $response = app(ArtifactDownloader::class)->stream($artifact);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame((string) $artifact->bytes, $response->headers->get('Content-Length'));

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="', $disposition);
        $this->assertMatchesRegularExpression(
            '/filename="[a-z0-9-]+-executed-pdf-[0-9a-f]{12}\.pdf"/',
            $disposition,
        );

        ob_start();
        $response->sendContent();
        $streamed = (string) ob_get_clean();

        $this->assertSame($artifact->sha256, hash('sha256', $streamed));
    }

    public function test_the_evidence_document_is_served_as_json_and_can_be_shown_inline(): void
    {
        $scenario = $this->finalized();

        $response = app(ArtifactDownloader::class)->stream(
            $this->artifact($scenario, ArtifactKind::EvidenceJson),
            inline: true,
        );

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline; filename="', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_an_unpublished_artifact_is_never_served(): void
    {
        $scenario = $this->finalized();
        $artifact = $this->artifact($scenario, ArtifactKind::ExecutedPdf);

        // A row without published_at describes bytes no completion was asserted over.
        $unpublished = new Artifact($artifact->only([
            'envelope_id', 'kind', 'disk', 'path', 'sha256', 'bytes', 'generation',
        ]));

        $this->expectException(FinalizationException::class);

        app(ArtifactDownloader::class)->stream($unpublished);
    }

    public function test_the_export_bundle_contains_every_file_with_matching_digests(): void
    {
        $scenario = $this->finalized();

        $path = app(EvidenceExporter::class)->bundle($scenario->envelope->refresh());

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);

            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $names[] = $zip->getNameIndex($index);
            }
            sort($names);

            $this->assertSame([
                'completion-report.pdf',
                'evidence.json',
                'executed-agreement.pdf',
                'manifest.json',
                'reviewed-revision.pdf',
                'seal-certificate.pem',
                'validation-report.json',
            ], $names);

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 32, JSON_THROW_ON_ERROR);

            $this->assertSame(EvidenceExporter::MANIFEST_VERSION, $manifest['manifest_version']);
            $this->assertSame($scenario->envelope->public_id, $manifest['envelope']);
            $this->assertSame('pades-b-b', $manifest['assurance_level_reached']);
            $this->assertNotSame('', $manifest['seal_key_id']);

            // Every listed digest is the digest of the file that is actually in the archive.
            $this->assertCount(6, $manifest['files']);

            foreach ($manifest['files'] as $entry) {
                $bytes = $zip->getFromName($entry['file']);

                $this->assertIsString($bytes, $entry['file'].' is listed but missing.');
                $this->assertSame($entry['sha256'], hash('sha256', $bytes));
                $this->assertSame($entry['bytes'], strlen($bytes));
                $this->assertNotSame('', trim($entry['covers']));
            }

            // The executed agreement in the archive is byte-identical to the published one.
            $this->assertSame(
                $this->artifact($scenario, ArtifactKind::ExecutedPdf)->sha256,
                hash('sha256', (string) $zip->getFromName('executed-agreement.pdf')),
            );

            // The certificate is a PEM, and it is labelled as the service's, not a signer's.
            $this->assertStringContainsString(
                '-----BEGIN CERTIFICATE-----',
                (string) $zip->getFromName('seal-certificate.pem'),
            );
            $this->assertStringContainsString(
                'The completion report is a report, not an X.509 certificate.',
                implode(' ', $manifest['notes']),
            );

            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    public function test_an_envelope_with_no_published_evidence_cannot_be_exported(): void
    {
        $scenario = FinalizationScenario::signed();

        $this->expectException(FinalizationException::class);

        app(EvidenceExporter::class)->bundle($scenario->envelope);
    }

    // ---------------------------------------------------------------------------------
    // Staging pruner
    // ---------------------------------------------------------------------------------

    public function test_the_pruner_never_removes_a_referenced_object_and_is_a_dry_run_by_default(): void
    {
        $scenario = $this->finalized();
        $referenced = Artifact::query()->pluck('path')->all();

        $orphan = ArtifactStorageKey::envelopePrefix(
            $scenario->signing->workspace->public_id,
            $scenario->envelope->public_id,
        ).'/executed_pdf-'.str_repeat('a', 64).'.pdf';

        Storage::disk('documents')->put($orphan, 'abandoned staging bytes');
        touch(Storage::disk('documents')->path($orphan), time() - (30 * 86_400));

        // Default: reports, changes nothing.
        $this->artisan('esign:artifacts:prune-staging')
            ->expectsOutputToContain('would remove '.$orphan)
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('documents')->exists($orphan));

        $this->artisan('esign:artifacts:prune-staging', ['--apply' => true])
            ->expectsOutputToContain('removed '.$orphan)
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('documents')->exists($orphan));

        foreach ($referenced as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' was reclaimed.');
        }
    }

    public function test_the_pruner_leaves_recent_unreferenced_objects_alone(): void
    {
        $scenario = $this->finalized();

        $recent = ArtifactStorageKey::envelopePrefix(
            $scenario->signing->workspace->public_id,
            $scenario->envelope->public_id,
        ).'/evidence_json-'.str_repeat('b', 64).'.json';

        Storage::disk('documents')->put($recent, '{}');

        // An object exists before the row that references it; reaping inside that window
        // would delete an upload that is still in flight.
        $this->artisan('esign:artifacts:prune-staging', ['--apply' => true])->assertSuccessful();

        $this->assertTrue(Storage::disk('documents')->exists($recent));
    }

    public function test_the_pruner_refuses_an_unreadable_age(): void
    {
        $this->artisan('esign:artifacts:prune-staging', ['--older-than' => 'soon'])
            ->assertExitCode(2);
    }

    /**
     * docs/security/review-2026-09.md finding B-2. Rule 3 of the command's own contract — "a
     * minimum age" — was documentation, not code: `--older-than=0` put the cutoff at now, and
     * `publish()` does not re-check that its uploaded objects still exist, so an object
     * deleted between upload and publication produced a `completed` envelope with immutable
     * artifact rows and no bytes.
     */
    public function test_the_pruner_refuses_an_age_short_enough_to_race_a_publication(): void
    {
        $this->artisan('esign:artifacts:prune-staging', ['--older-than' => '0'])
            ->assertExitCode(2);

        $this->artisan('esign:artifacts:prune-staging', ['--older-than' => '30s'])
            ->assertExitCode(2);
    }

    /**
     * docs/security/review-2026-09.md finding B-1, the sharp end of it.
     *
     * `artifacts.disk` is frozen at publication, and the pruner scoped "referenced" by the
     * *current* `ESIGN_DOCUMENTS_DISK`. Rename the disk — the same-bytes-new-name migration
     * docs/BLOB_STORAGE.md contemplates — and every published artifact looked unreferenced at
     * once, legal hold included. This is the one evidence-deleting path in the application
     * with no legal-hold consultation at all, so the set had to be right.
     */
    public function test_a_renamed_disk_does_not_make_every_published_artifact_look_unreferenced(): void
    {
        $scenario = $this->finalized();
        $referenced = Artifact::query()->pluck('path')->all();
        $this->assertNotEmpty($referenced);

        // Age every published object past the threshold, so only the reference check stands
        // between them and deletion.
        foreach ($referenced as $path) {
            touch(Storage::disk('documents')->path($path), time() - (30 * 86_400));
        }

        // The rows still say the old disk name; the configuration now says another.
        Artifact::query()->getQuery()->update(['disk' => 'documents-legacy']);

        $this->artisan('esign:artifacts:prune-staging', ['--apply' => true])->assertSuccessful();

        foreach ($referenced as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' was reclaimed.');
        }
    }

    /**
     * The abort docs/BLOB_STORAGE.md asks for, as a second line behind the fix above: when
     * the referenced set stops working for any reason, everything it protected looks
     * unreferenced at once, and the ratio is what says so.
     */
    public function test_the_pruner_refuses_when_an_implausible_share_looks_unreferenced(): void
    {
        $scenario = $this->finalized();
        $referenced = Artifact::query()->pluck('path')->all();

        foreach ($referenced as $path) {
            touch(Storage::disk('documents')->path($path), time() - (30 * 86_400));
        }

        // Whatever the cause — a truncated table, a migration halfway through — the shape is
        // the same: nothing is referenced any more. (`artifacts` refuses a normal delete, so
        // the row set is cleared through the query builder.)
        Artifact::query()->getQuery()->delete();

        $this->artisan('esign:artifacts:prune-staging', ['--apply' => true])
            ->expectsOutputToContain('Refusing')
            ->assertFailed();

        foreach ($referenced as $path) {
            $this->assertTrue(Storage::disk('documents')->exists($path), $path.' was reclaimed.');
        }
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    private function finalized(): FinalizationScenario
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        return $scenario;
    }

    private function artifact(FinalizationScenario $scenario, ArtifactKind $kind): Artifact
    {
        return Artifact::query()
            ->where('envelope_id', $scenario->envelope->getKey())
            ->where('kind', $kind->value)
            ->firstOrFail();
    }
}
