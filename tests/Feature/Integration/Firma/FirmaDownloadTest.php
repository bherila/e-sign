<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Integration\Firma\DownloadUrlIssuer;
use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FirmaFacadeScenario;
use Tests\TestCase;

/**
 * `GET /signing-requests/{id}/download`, and the bytes the link it returns serves.
 *
 * Two things are being tested and they are different claims.
 *
 * The **JSON** half is a compatibility claim: the body has to carry `download_url` and
 * `expires_at`, `is_partial` has to mean what the recorded fixtures show it means, and a
 * draft has to answer upstream's own `409 no_document_available`.
 *
 * The **bytes** half is a product claim: what comes back is exactly what was retained. A
 * finished agreement serves the sealed executed PDF; anything else serves the reviewed
 * revision, byte-for-byte, never re-rendered (AGENTS.md). And it streams through the
 * application on every driver — no storage URL is ever pre-signed
 * (`docs/BLOB_STORAGE.md` rule 1), which is asserted here by checking that the link points
 * at this application and that following it without a valid signature fails.
 */
class FirmaDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/functions/v1/signing-request-api/signing-requests';

    /**
     * The route name the issuer mints against really exists.
     *
     * A rename that missed one of the two would mint links to nothing, and the failure would
     * only show up when a consumer followed one.
     */
    public function test_the_download_route_name_matches_the_issuer(): void
    {
        $this->assertTrue(Route::has(DownloadUrlIssuer::ROUTE));
    }

    /**
     * A finished agreement: the sealed executed PDF, not the document the parties were shown.
     */
    public function test_a_finished_request_serves_the_executed_pdf(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->completed();

        $body = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json();

        $this->assertSame('finished', $body['status']);
        $this->assertFalse($body['is_partial']);
        $this->assertNotNull($body['generated_at']);

        // An app URL, not a storage pre-sign, and it expires.
        $this->assertStringContainsString(FirmaProfile::BASE_PATH.'/downloads/', $body['download_url']);
        $this->assertStringContainsString('signature=', $body['download_url']);
        $this->assertStringContainsString('expires=', $body['download_url']);

        $artifact = Artifact::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('kind', ArtifactKind::ExecutedPdf->value)
            ->sole();

        $response = $this->get($body['download_url']);
        $response->assertOk();

        // Exactly the retained bytes: the digest recorded and re-verified at publication.
        $this->assertSame($artifact->sha256, hash('sha256', $response->streamedContent()));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * An unfinished request: the reviewed revision, flagged partial.
     *
     * The recorded fixtures show upstream answering HTTP 200 with `is_partial: true` and a
     * document-only URL for a request that is still out, rather than erroring
     * (`tests/Fixtures/firma/firma-compat-v1/README.md`). Disagreement D11 notes upstream
     * gates this on a setting that does not exist in its own schema; there is nothing to gate
     * on, so the flag is simply reported truthfully.
     */
    public function test_a_sent_request_serves_the_reviewed_revision_as_partial(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $body = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json();

        $this->assertSame('in_progress', $body['status']);
        $this->assertTrue($body['is_partial']);

        $revision = $envelope->documentRevision;
        $response = $this->get($body['download_url']);

        $response->assertOk();
        $this->assertSame($revision->sha256, hash('sha256', $response->streamedContent()));
        $this->assertSame($revision->sha256, $response->headers->get('X-Document-Sha256'));

        // Byte-for-byte what the parties were shown, from the disk and not re-rendered.
        $this->assertSame(
            Storage::disk($revision->disk)->get($revision->path),
            $response->streamedContent(),
        );
    }

    /** A withdrawn request keeps serving the document, flagged partial and cancelled. */
    public function test_a_cancelled_request_serves_the_reviewed_revision(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->cancelled();

        $body = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json();

        $this->assertSame('cancelled', $body['status']);
        $this->assertTrue($body['is_partial']);
        $this->get($body['download_url'])->assertOk();
    }

    /**
     * A draft is upstream's own `409 no_document_available`.
     *
     * Nothing has been shown to anybody, so there is no document under signature to hand out.
     * The token is verbatim upstream's.
     */
    public function test_a_draft_has_no_document_available(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->signing->draft();

        $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(409)
            ->assertJsonPath('error', 'no_document_available');
    }

    /**
     * The link is short-lived, and the expiry is signed rather than advisory.
     */
    public function test_a_download_link_expires(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $body = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json();

        $this->travelTo(now()->addMinutes(FirmaProfile::DOWNLOAD_URL_TTL_MINUTES + 1));

        $this->get($body['download_url'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * An edited link is refused: the signature covers the token and the expiry together.
     *
     * This is what makes the capability narrow. Without it, a caller holding one link could
     * edit the envelope id in it and read another agreement — including one in another
     * workspace, since this route carries no credential to constrain by.
     */
    public function test_a_tampered_download_link_is_refused(): void
    {
        $mine = FirmaFacadeScenario::create();
        $issued = $mine->credential();
        $envelope = $mine->sent();

        $theirs = FirmaFacadeScenario::create();
        $other = $theirs->sent();

        $body = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json();

        $issuer = app(DownloadUrlIssuer::class);
        $tampered = str_replace(
            $issuer->encode($envelope, DownloadUrlIssuer::REVIEW),
            $issuer->encode($other, DownloadUrlIssuer::REVIEW),
            $body['download_url'],
        );

        $this->assertNotSame($body['download_url'], $tampered);
        $this->get($tampered)->assertStatus(403);
    }

    /** An unsigned request to the streaming route is refused outright. */
    public function test_the_streaming_route_refuses_an_unsigned_request(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $envelope = $scenario->sent();
        $token = app(DownloadUrlIssuer::class)->encode($envelope, DownloadUrlIssuer::REVIEW);

        $this->get('/'.FirmaProfile::BASE_PATH.'/downloads/'.$token)
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    /**
     * The detail response's three URLs appear only once the agreement is executed, which is
     * what the recorded fixtures show.
     */
    public function test_the_detail_download_urls_appear_only_when_finished(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();

        $sent = $scenario->sent();
        $body = $this->getJson(
            self::BASE.'/'.$sent->public_id,
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json();

        foreach (['final_document', 'document_only', 'certificate_only'] as $kind) {
            $this->assertNull($body[$kind.'_download_url'], $kind);
            $this->assertNull($body[$kind.'_download_error'], $kind);
        }
        $this->assertFalse($body['certificate']['generated']);

        $finished = FirmaFacadeScenario::create();
        $finishedIssued = $finished->credential();
        $completed = $finished->completed();

        $body = $this->getJson(
            self::BASE.'/'.$completed->public_id,
            FirmaFacadeScenario::headers($finishedIssued),
        )->assertOk()->json();

        foreach (['final_document', 'document_only', 'certificate_only'] as $kind) {
            $this->assertNotNull($body[$kind.'_download_url'], $kind);
            $this->assertNull($body[$kind.'_download_error'], $kind);
        }

        $this->assertTrue($body['certificate']['generated']);
        $this->assertNotNull($body['certificate']['generated_on']);
        $this->assertFalse($body['certificate']['has_error']);

        // The certificate link serves the completion report, which is a report and not a
        // credential belonging to any signer (AGENTS.md, "Honest language").
        $report = Artifact::query()
            ->where('envelope_id', $completed->getKey())
            ->where('kind', ArtifactKind::CompletionReport->value)
            ->sole();

        $response = $this->get($body['certificate_only_download_url']);
        $response->assertOk();
        $this->assertSame($report->sha256, hash('sha256', $response->streamedContent()));
    }

    /**
     * A completed envelope with no published artifact is a 501, not a 409.
     *
     * Telling a caller their finished agreement is unfinished would be false. This is the
     * same distinction the native API draws, from the same locator.
     */
    public function test_a_finished_request_with_no_artifact_is_not_implemented(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->completed();

        // Unpublish the executed PDF the way a deployment without finalization would look:
        // the envelope is complete and no retrievable artifact exists.
        Artifact::query()->where('envelope_id', $envelope->getKey())->delete();

        $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/download',
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported');
    }

    /**
     * `?include=images` is what returns a signature's bytes; the default is the marker.
     *
     * The recorded fixtures carry both: `Recipient Signature` where a signature was rendered
     * as text, and a PNG data URL where one was drawn. The facade emits the marker by default
     * because an envelope carries one signature image per signer and a response that ships
     * them unasked puts them in the caller's logs.
     */
    public function test_signature_values_are_a_marker_until_images_are_asked_for(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->completed();

        $default = $this->fieldsByType($envelope, $issued, '');
        $this->assertSame(FirmaProfile::SIGNATURE_MARKER, $default['signature']['value']);
        $this->assertSame(FirmaProfile::SIGNATURE_MARKER, $default['signature']['final_value']);

        $withImages = $this->fieldsByType($envelope, $issued, '?include=images');
        $this->assertStringStartsWith('data:image/png;base64,', $withImages['signature']['value']);

        // A text field is unaffected either way.
        $this->assertSame($default['text']['value'], $withImages['text']['value']);
    }

    /** An unsigned signature field has no value at all, rather than an empty marker. */
    public function test_an_unsigned_signature_field_has_a_null_value(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $fields = $this->fieldsByType($envelope, $issued, '');

        $this->assertNull($fields['signature']['value']);
        $this->assertNull($fields['signature']['final_value']);
    }

    /**
     * @return array<string, array<string, mixed>> Field type => the first row of that type.
     */
    private function fieldsByType(Envelope $envelope, object $issued, string $query): array
    {
        $rows = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/fields'.$query,
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json('results');

        $byType = [];

        foreach ($rows as $row) {
            $byType[$row['field_type']] ??= $row;
        }

        return $byType;
    }
}
