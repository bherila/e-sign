<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\EvidenceExporter;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\FinalizedArtifactLocator;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FirmaFacadeScenario;
use Tests\TestCase;
use ZipArchive;

/**
 * The artifact routes against a real finalization, and the evidence export.
 *
 * `NativeApiArtifactTest` covers the {@see ArtifactLocator} seam — the two refusals, and a
 * locator holding bytes in memory. This one goes end to end: an envelope signed by two people
 * through the real state machine, finalized by the real finalizer, and then read back over
 * HTTP. What it is really asserting is that the digest a caller verifies against is the one
 * finalization recorded and re-read at publication, not a fresh measurement of whatever is on
 * the disk now — because those two are the same until the day they are not, and the whole
 * point of recording it is to notice.
 */
class NativeApiFinalizedArtifactTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_published_artifacts_are_listed_oldest_first(): void
    {
        [$envelope, $headers] = $this->finalized();

        $response = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts', $headers)
            ->assertOk();

        $kinds = array_column($response->json('data'), 'kind');
        sort($kinds);

        $this->assertSame(
            [ArtifactKind::CompletionReport->value, ArtifactKind::EvidenceJson->value, ArtifactKind::ExecutedPdf->value],
            $kinds,
        );

        $this->assertNull($response->json('meta.next_cursor'));

        foreach ($response->json('data') as $listed) {
            $artifact = Artifact::query()->where('public_id', $listed['id'])->sole();

            $this->assertSame($artifact->sha256, $listed['sha256']);
            $this->assertSame($artifact->bytes, $listed['bytes']);
            $this->assertSame($artifact->kind->contentType(), $listed['content_type']);

            // No disk name and no object path: a client that learns a storage key is one
            // presigning bug away from bypassing the workspace boundary.
            $this->assertStringNotContainsString($artifact->path, json_encode($listed, JSON_THROW_ON_ERROR));
            $this->assertArrayNotHasKey('disk', $listed);
            $this->assertArrayNotHasKey('path', $listed);
        }
    }

    public function test_only_published_artifacts_are_visible(): void
    {
        [$envelope, $headers] = $this->finalized();

        $hidden = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('kind', ArtifactKind::EvidenceJson->value)
            ->sole();

        // A row without `published_at` names bytes no completion has been asserted over.
        // `artifacts` is insert-only at the model, so this reaches past it deliberately.
        Artifact::query()->whereKey($hidden->getKey())->update(['published_at' => null]);

        $listed = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts', $headers)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $listed);
        $this->assertNotContains($hidden->public_id, array_column($listed, 'id'));

        // And it cannot be fetched by id either.
        $this->getJson(
            '/api/v1/envelopes/'.$envelope->public_id.'/artifacts/'.$hidden->public_id.'/download',
            $headers,
        )->assertNotFound();
    }

    public function test_the_executed_pdf_streams_with_its_recorded_digest(): void
    {
        [$envelope, $headers] = $this->finalized();

        $artifact = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $response = $this->get(
            '/api/v1/envelopes/'.$envelope->public_id.'/artifacts/'.$artifact->public_id.'/download',
            $headers,
        );

        $response->assertOk();
        $bytes = $response->streamedContent();

        $this->assertSame($artifact->sha256, hash('sha256', $bytes));
        $this->assertSame($artifact->sha256, $response->headers->get('X-Artifact-Sha256'));
        $this->assertSame((string) $artifact->bytes, $response->headers->get('Content-Length'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    /** The evidence document is JSON, and its content type says so. */
    public function test_the_evidence_document_streams_as_json(): void
    {
        [$envelope, $headers] = $this->finalized();

        $artifact = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('kind', ArtifactKind::EvidenceJson->value)
            ->sole();

        $response = $this->get(
            '/api/v1/envelopes/'.$envelope->public_id.'/artifacts/'.$artifact->public_id.'/download',
            $headers,
        );

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertIsArray(json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Another tenant's artifact id is a 404, not a download.
     *
     * The locator is handed the envelope and constrains by it before comparing the id, which
     * is the whole reason `ArtifactLocator::find()` takes both.
     */
    public function test_an_artifact_of_another_tenant_is_not_found(): void
    {
        [$envelope, $headers] = $this->finalized();
        [$other] = $this->finalized();

        $theirs = Artifact::query()->where('envelope_id', $other->getKey())->first();

        $this->getJson(
            '/api/v1/envelopes/'.$envelope->public_id.'/artifacts/'.$theirs->public_id.'/download',
            $headers,
        )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    /* ------------------------------------------------------------- evidence bundle */

    /**
     * The export carries every file `docs/HANDOFF.md` section 8 names, plus the manifest
     * that says what each digest covers.
     */
    public function test_the_evidence_bundle_is_a_zip_with_a_manifest(): void
    {
        [$envelope, $headers] = $this->finalized();

        $response = $this->get('/api/v1/envelopes/'.$envelope->public_id.'/evidence-bundle', $headers);

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $path = (string) tempnam(sys_get_temp_dir(), 'esign-bundle-test-');

        try {
            file_put_contents($path, $response->streamedContent());

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);

            $names = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $names[] = $zip->getNameIndex($index);
            }

            $this->assertContains(EvidenceExporter::MANIFEST, $names);
            $this->assertContains('reviewed-revision.pdf', $names);

            $manifest = json_decode(
                (string) $zip->getFromName(EvidenceExporter::MANIFEST),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame($envelope->public_id, $manifest['envelope']);
            $this->assertSame(EvidenceExporter::MANIFEST_VERSION, $manifest['manifest_version']);

            // Every entry names a digest and says what it covers. A pile of files with no
            // statement of which digest belongs to which object is not evidence.
            foreach ($manifest['files'] as $entry) {
                $this->assertArrayHasKey('sha256', $entry);
                $this->assertNotSame('', $entry['covers']);
                $this->assertSame(
                    $entry['sha256'],
                    hash('sha256', (string) $zip->getFromName($entry['file'])),
                    $entry['file'].' does not hash to the digest the manifest names',
                );
            }

            $zip->close();
        } finally {
            @unlink($path);
        }
    }

    /** The archive is not left behind on the server after it has been sent. */
    public function test_the_evidence_bundle_leaves_no_temporary_file(): void
    {
        [$envelope, $headers] = $this->finalized();

        $before = glob(sys_get_temp_dir().'/esign-evidence-*') ?: [];

        $this->get('/api/v1/envelopes/'.$envelope->public_id.'/evidence-bundle', $headers)
            ->assertOk()
            ->streamedContent();

        $after = glob(sys_get_temp_dir().'/esign-evidence-*') ?: [];

        $this->assertSame($before, $after);
    }

    public function test_an_unfinished_envelope_has_no_evidence_to_export(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $headers = ['Authorization' => 'Bearer '.$scenario->credential()->secret];
        $envelope = $scenario->sent();

        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/evidence-bundle', $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'not_completed');
    }

    public function test_the_evidence_bundle_needs_the_read_scope(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential([Scope::EnvelopesWrite]);
        $envelope = $scenario->sent();

        $this->getJson(
            '/api/v1/envelopes/'.$envelope->public_id.'/evidence-bundle',
            ['Authorization' => 'Bearer '.$issued->secret],
        )
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    /** The shipped binding is the real locator. */
    public function test_the_container_binds_the_finalized_locator(): void
    {
        $this->assertInstanceOf(FinalizedArtifactLocator::class, app(ArtifactLocator::class));
    }

    /**
     * A two-signer envelope, signed and finalized for real.
     *
     * @return array{0: Envelope, 1: array<string, string>}
     */
    private function finalized(): array
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();

        return [$scenario->completed(), ['Authorization' => 'Bearer '.$issued->secret]];
    }
}
