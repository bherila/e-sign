<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Integration\Firma\Uploads\FirmaDocumentUpload;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\PdfFixtures;
use Tests\Support\RetentionScenario;
use Tests\TestCase;

/**
 * The write side of a document set on the Firma facade (issue #182).
 *
 * - **Upload a small document** — `POST /documents/inline`, base64, one call.
 * - **Upload a large document** — `POST /documents`, then `PUT` the raw bytes to `upload_url`.
 * - `document_id` / `document_ids` on create, with `page_number` absolute across the set.
 * - A draft's `/documents` routes: add, reorder, replace or rename, remove — each through the
 *   state machine, each carrying every placed field with the page it is on.
 */
class FirmaDocumentSetWriteTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/functions/v1/signing-request-api';

    private const BASE = self::API.'/signing-requests';

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $scenario = FirmaFacadeScenario::create();
        $this->headers = FirmaFacadeScenario::headers($scenario->credential());
    }

    /* ------------------------------------------------------------------ uploads */

    public function test_a_large_upload_is_two_steps_and_streams_through_intake(): void
    {
        $bytes = PdfFixtures::bytes('multi-page-mixed-size');

        $issued = $this->postJson(self::API.'/documents', [
            'file_name' => 'master-agreement.pdf',
            'file_size' => strlen($bytes),
            'content_type' => 'application/pdf',
        ], $this->headers)->assertCreated();

        $this->assertSame(FirmaProfile::UPLOAD_URL_TTL_MINUTES * 60, $issued->json('expires_in'));
        $url = (string) $issued->json('upload_url');
        $this->assertStringContainsString('/documents/uploads/'.$issued->json('document_id'), $url);
        $this->assertStringContainsString('signature='.$issued->json('upload_token'), $url);

        // No API key: the URL is the capability, as upstream's pre-signed one is.
        $this->put($url, [], [])->assertStatus(400);
        $this->putRaw($url, $bytes)->assertOk()
            ->assertJsonPath('document_id', $issued->json('document_id'))
            ->assertJsonPath('page_count', 3)
            ->assertJsonPath('file_name', 'master-agreement.pdf');

        // Retained byte-for-byte: an upload is written once.
        $this->putRaw($url, $bytes)->assertStatus(409)->assertJsonPath('error', 'invalid_state');
    }

    public function test_an_upload_url_that_was_edited_or_expired_is_refused(): void
    {
        $url = (string) $this->requestUpload(1000)->json('upload_url');

        $this->putRaw($url.'x', 'whatever')->assertForbidden();

        $this->travel(FirmaProfile::UPLOAD_URL_TTL_MINUTES + 1)->minutes();
        $this->putRaw($url, PdfFixtures::bytes('single-page-letter'))->assertForbidden();
    }

    public function test_an_upload_is_held_to_what_was_declared(): void
    {
        $bytes = PdfFixtures::bytes('single-page-letter');

        $this->putRaw((string) $this->requestUpload(strlen($bytes) - 1)->json('upload_url'), $bytes)
            ->assertStatus(413)->assertJsonPath('error', 'document_set_too_large');

        $this->putRaw((string) $this->requestUpload(strlen($bytes))->json('upload_url'), $bytes, 'image/png')
            ->assertStatus(400)->assertJsonPath('error', 'invalid_request');

        $this->postJson(self::API.'/documents', ['file_name' => 'x.pdf', 'file_size' => FirmaProfile::MAX_UPLOAD_BYTES + 1, 'content_type' => 'application/pdf'], $this->headers)
            ->assertStatus(400);

        // Upstream converts Word documents; this build does not, and says so by name.
        $this->postJson(self::API.'/documents', [
            'file_name' => 'x.docx',
            'file_size' => 10,
            'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], $this->headers)->assertStatus(501)->assertJsonPath('details.unsupported_option', 'content_type');
    }

    /** The 6 MiB JSON ceiling is not a large upload's limit; its declared size is. */
    public function test_a_large_uploads_bytes_are_not_held_to_the_json_body_ceiling(): void
    {
        $body = str_repeat('%', 7 * 1024 * 1024);

        $this->putRaw((string) $this->requestUpload(strlen($body))->json('upload_url'), $body)
            ->assertStatus(400)
            ->assertJsonMissing(['error' => 'request_too_large']);
    }

    /**
     * Only a raw PDF body is exempt from the JSON ceiling. A JSON body on the same path would be
     * decoded by the framework's global middleware before the signature is even checked, so it is
     * held to 6 MiB like any other body here, and refused before anything reads it.
     */
    public function test_a_json_body_on_the_upload_path_is_still_held_to_the_ceiling(): void
    {
        $body = '{"a":"'.str_repeat('x', 7 * 1024 * 1024).'"}';

        $this->putRaw(self::API.'/documents/uploads/01JSYNTHETICUPLOAD00000000', $body, 'application/json')
            ->assertStatus(413)->assertJsonPath('error', 'request_too_large');
    }

    public function test_an_upload_declares_its_length_and_stays_within_50_mb(): void
    {
        $url = (string) $this->requestUpload(1000)->json('upload_url');

        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/pdf', 'CONTENT_LENGTH' => (string) (FirmaProfile::MAX_UPLOAD_BYTES + 1)], 'x')
            ->assertStatus(413)->assertJsonPath('error', 'request_too_large');

        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/pdf'], 'x')
            ->assertStatus(411);
    }

    public function test_a_refused_pdf_is_reported_at_upload_and_cannot_be_used(): void
    {
        $id = $this->postJson(self::API.'/documents/inline', [
            'file_name' => 'locked.pdf',
            'document' => base64_encode(PdfFixtures::bytes('encrypted-aes128')),
        ], $this->headers)->assertStatus(400)->json('details.document_id');

        $this->assertNotNull($id);
        $this->postJson(self::BASE, $this->body(['document_id' => $id]), $this->headers)
            ->assertStatus(400)->assertJsonPath('details.document_id', $id);
    }

    /** An upload nobody used is an unreferenced document, and retention reclaims it. */
    public function test_an_unused_upload_is_reclaimed_by_retention(): void
    {
        $id = $this->smallUpload('single-page-letter', 'never-used.pdf');
        $documentId = (int) FirmaDocumentUpload::query()->where('public_id', $id)->value('document_id');
        RetentionScenario::backdate('documents', $documentId, ['created_at' => RetentionScenario::daysAgo(200)]);

        $this->artisan('esign:retention:run', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('documents', ['id' => $documentId]);
        $this->assertNull(FirmaDocumentUpload::query()->where('public_id', $id)->value('document_id'));
        $this->postJson(self::BASE, $this->body(['document_id' => $id]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'document_upload_not_found');
    }

    /* ------------------------------------------------------------------- create */

    public function test_a_set_is_created_from_uploads_with_absolute_pages(): void
    {
        $first = $this->largeUpload('multi-page-mixed-size', 'master-agreement.pdf');
        $second = $this->smallUpload('single-page-letter', 'schedule-a.pdf');

        $created = $this->postJson(self::BASE, $this->body(['document_ids' => [$first, $second]], 4), $this->headers)
            ->assertCreated()
            ->assertJsonPath('document_url', null)
            ->assertJsonPath('page_count', 4);

        $envelope = $this->envelope((string) $created->json('id'));
        $set = $envelope->documentSet();

        $this->assertSame(2, $set->count());
        $this->assertSame($set->digest(), $envelope->document_sha256);
        $this->assertSame(4, $envelope->fieldSchema()->fields[0]->page);

        // Percent of the page the field is on: the schedule, US Letter.
        $this->assertSame(round(10.0 / 100 * 612, 3), $envelope->fieldSchema()->fields[0]->rect->x);

        $detail = $this->getJson(self::BASE.'/'.$envelope->public_id, $this->headers)->assertOk();
        $this->assertSame(['master-agreement.pdf', 'schedule-a.pdf'], array_column($detail->json('documents'), 'original_filename'));
        $this->assertSame([3, 1], array_column($detail->json('documents'), 'page_count'));
        $this->assertSame(1, $detail->json('documents_version'));
        $this->assertSame(1, $detail->json('documents_layout_version'));

        // An upload is used once.
        $this->postJson(self::BASE, $this->body(['document_ids' => [$first, $second]]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'document_upload_not_found')->assertJsonPath('details.document_id', $first);
    }

    public function test_a_single_upload_is_a_single_document_request(): void
    {
        $id = $this->smallUpload('single-page-letter', 'nda.pdf');

        $created = $this->postJson(self::BASE, $this->body(['document_id' => $id]), $this->headers)->assertCreated();
        $envelope = $this->envelope((string) $created->json('id'));

        $this->assertFalse($envelope->documentSet()->isMultiple());
        $this->assertSame(0, EnvelopeDocument::query()->where('envelope_id', $envelope->getKey())->count());
        $this->assertNotNull($created->json('document_url'));
        $this->assertSame([null], array_column($this->getJson(self::BASE.'/'.$envelope->public_id, $this->headers)->json('documents'), 'document_id'));

        // Adding to it makes a set, and gives both documents ids.
        $body = $this->postJson(self::BASE.'/'.$envelope->public_id.'/documents', ['document_id' => $this->smallUpload('xref-stream', 'schedule.pdf')], $this->headers)
            ->assertOk();
        $this->assertCount(2, array_filter(array_column($body->json('documents'), 'document_id')));
        $this->assertTrue($envelope->refresh()->documentSet()->isMultiple());
        $this->assertSame(1, $envelope->fieldSchema()->fields[0]->page);
    }

    public function test_the_create_refusals_are_upstreams(): void
    {
        $a = $this->smallUpload('single-page-letter', 'a.pdf');

        $this->postJson(self::BASE, $this->body(['document_ids' => [$a, $a]]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'duplicate_document_id');

        $this->postJson(self::BASE, $this->body(['document_ids' => array_map(static fn (int $i): string => sprintf('01JSYNTHETICUPLOAD%08d', $i), range(1, 21))]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'max_documents_exceeded');

        $this->postJson(self::BASE, $this->body(['document_ids' => [$a, '01JSYNTHETICNOSUCHUPLOAD00']]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'document_upload_not_found');

        $this->postJson(self::BASE, $this->body(['document_id' => $a, 'document' => base64_encode(PdfFixtures::bytes('single-page-letter'))]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'conflicting_document_source');

        // A refused request uses nothing: the upload is still good.
        $this->postJson(self::BASE, $this->body(['document_id' => $a]), $this->headers)->assertCreated();
    }

    public function test_an_expired_upload_is_not_found(): void
    {
        $id = $this->smallUpload('single-page-letter', 'a.pdf');

        $this->travel(FirmaProfile::UPLOAD_USABLE_HOURS + 1)->hours();

        $this->postJson(self::BASE, $this->body(['document_id' => $id]), $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'document_upload_not_found');
    }

    /* -------------------------------------------------------------------- edit */

    public function test_adding_a_document_first_carries_every_field_with_its_page(): void
    {
        $envelope = $this->draftSet();
        $before = $envelope->document_sha256;
        $added = $this->smallUpload('single-page-letter', 'cover.pdf');

        $body = $this->postJson(self::BASE.'/'.$envelope->public_id.'/documents', [
            'document_id' => $added,
            'position' => 1,
            'label' => 'Cover letter',
        ], $this->headers)->assertOk();

        $this->assertSame(['Cover letter', 'master-agreement.pdf', 'schedule-a.pdf'], array_column($body->json('documents'), 'label'));
        $this->assertSame(['cover.pdf', 'master-agreement.pdf', 'schedule-a.pdf'], array_column($body->json('documents'), 'original_filename'));
        $this->assertSame(2, $body->json('documents_version'));
        $this->assertSame(2, $body->json('documents_layout_version'));

        $envelope->refresh();
        // The schedule's signature was on absolute page 4; one page now comes before it.
        $this->assertSame(5, $envelope->fieldSchema()->fields[0]->page);
        $this->assertSame(hash('sha256', $envelope->fieldSchema()->canonicalJson()), $envelope->field_schema_sha256);
        $this->assertNotSame($before, $envelope->document_sha256);
        $this->assertSame($envelope->documentSet()->digest(), $envelope->document_sha256);
        $this->assertSame(EnvelopeState::Draft, $envelope->state);

        // The added upload is used.
        $this->postJson(self::BASE.'/'.$envelope->public_id.'/documents', ['document_id' => $added], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'document_upload_not_found');
    }

    public function test_reordering_moves_each_field_with_its_document_and_keeps_the_ids(): void
    {
        $envelope = $this->draftSet();
        $ids = $this->documentIds($envelope);

        $body = $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', ['document_ids' => array_reverse($ids)], $this->headers)
            ->assertOk();

        $this->assertSame(array_reverse($ids), array_column($body->json('documents'), 'document_id'));
        $this->assertSame([1, 3], array_column($body->json('documents'), 'page_count'));

        // The schedule is first now, so its one page is page 1.
        $this->assertSame(1, $envelope->refresh()->fieldSchema()->fields[0]->page);
        $this->assertSame(2, $envelope->documents_layout_version);

        $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', ['document_ids' => [$ids[0]]], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'invalid_document_order');
        $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', ['document_ids' => [$ids[0], $ids[0]]], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'invalid_document_order');
        $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', ['document_ids' => [$ids[0], '01JSYNTHETICNOSUCHDOCUMENT']], $this->headers)
            ->assertStatus(404)->assertJsonPath('error', 'document_not_found');
    }

    public function test_renaming_changes_the_version_and_nothing_an_acceptance_binds(): void
    {
        $envelope = $this->draftSet();
        $ids = $this->documentIds($envelope);
        $digest = $envelope->document_sha256;
        $schema = $envelope->field_schema_sha256;

        $this->patchJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[0], ['label' => 'Master agreement'], $this->headers)
            ->assertOk()
            ->assertJsonPath('documents.0.label', 'Master agreement')
            ->assertJsonPath('documents_version', 2)
            ->assertJsonPath('documents_layout_version', 1);

        $envelope->refresh();
        $this->assertSame($digest, $envelope->document_sha256);
        $this->assertSame($schema, $envelope->field_schema_sha256);

        $this->patchJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[0], ['label' => null], $this->headers)
            ->assertOk()->assertJsonPath('documents.0.label', null);

        $this->patchJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[0], [], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    }

    public function test_a_replacement_must_have_the_same_pages(): void
    {
        $envelope = $this->draftSet();
        $ids = $this->documentIds($envelope);

        // Same shape, different bytes: one US Letter page.
        $same = $this->smallUpload('xref-stream', 'schedule-a-v2.pdf');
        $this->patchJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[1], ['document_id' => $same], $this->headers)
            ->assertOk()
            ->assertJsonPath('documents.1.document_id', $ids[1])
            ->assertJsonPath('documents_layout_version', 1);
        $this->assertSame($envelope->refresh()->documentSet()->digest(), $envelope->document_sha256);
        $this->assertSame(4, $envelope->fieldSchema()->fields[0]->page);

        $longer = $this->smallUpload('nda-two-signers', 'two-pages.pdf');
        $this->patchJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[1], ['document_id' => $longer], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'page_count_mismatch');

        // Three pages, but the first is landscape where the original's is portrait.
        $rotated = $this->smallUpload('rotated-pages', 'rotated.pdf');
        $this->patchJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[0], ['document_id' => $rotated], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'dimension_mismatch')->assertJsonPath('details.page', 1);

        // Neither refusal used its upload.
        $this->assertSame(0, FirmaDocumentUpload::query()->whereIn('public_id', [$longer, $rotated])->whereNotNull('consumed_at')->count());
    }

    public function test_removing_refuses_to_strand_a_field_and_never_removes_the_last_document(): void
    {
        $envelope = $this->draftSet();
        $ids = $this->documentIds($envelope);

        // The seller's signature is on the schedule.
        $this->deleteJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[1], [], $this->headers)
            ->assertStatus(400)
            ->assertJsonPath('details.reason', 'fields_on_document')
            ->assertJsonPath('details.document_id', $ids[1]);

        // The master agreement has no fields, so it can go, and the schedule's page becomes 1.
        $this->deleteJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[0], [], $this->headers)
            ->assertOk()
            ->assertJsonPath('documents.0.document_id', $ids[1]);

        $envelope->refresh();
        $this->assertSame(1, $envelope->fieldSchema()->fields[0]->page);
        $this->assertFalse($envelope->documentSet()->isMultiple());
        // A set of one binds its document's own digest, exactly as an envelope that was always one.
        $this->assertSame($envelope->documentSet()->first()->sha256, $envelope->document_sha256);

        $this->deleteJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[1], [], $this->headers)
            ->assertStatus(400)->assertJsonPath('error', 'min_documents_required');
    }

    public function test_an_added_document_is_held_to_the_sets_limits(): void
    {
        $envelope = $this->draftSet();
        $added = $this->smallUpload('single-page-letter', 'extra.pdf');

        $this->postJson(self::BASE.'/'.$envelope->public_id.'/documents', ['document_id' => $added, 'position' => 9], $this->headers)
            ->assertStatus(400)->assertJsonPath('details.reason', 'invalid_position');

        // Refused before anything moved, and without using the upload.
        $this->assertSame(1, $envelope->refresh()->documents_version);
        $this->assertNull(FirmaDocumentUpload::query()->where('public_id', $added)->value('consumed_at'));
    }

    public function test_an_edited_draft_sends_and_binds_the_edited_set(): void
    {
        $envelope = $this->draftSet();
        $ids = $this->documentIds($envelope);

        $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', ['document_ids' => array_reverse($ids)], $this->headers)->assertOk();
        $this->postJson(self::BASE.'/'.$envelope->public_id.'/send', [], $this->headers)->assertOk();

        $envelope->refresh();
        $this->assertSame(EnvelopeState::Sent, $envelope->state);
        $this->assertSame($envelope->documentSet()->digest(), $envelope->document_sha256);

        $this->postJson(self::BASE.'/'.$envelope->public_id.'/documents', ['document_id' => $this->smallUpload('single-page-letter', 'late.pdf')], $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'document_set_locked');
    }

    /** A link issued before a reorder still names the document it was issued for. */
    public function test_a_download_link_follows_its_document_through_a_reorder(): void
    {
        $envelope = $this->draftSet();
        $ids = $this->documentIds($envelope);

        $link = (string) $this->getJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$ids[1].'/download', $this->headers)
            ->assertOk()->json('download_url');

        $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', ['document_ids' => array_reverse($ids)], $this->headers)->assertOk();

        $this->get($link)->assertOk()->assertStreamedContent(PdfFixtures::bytes('single-page-letter'));
    }

    /* ------------------------------------------------------- layout precondition */

    public function test_a_field_write_on_a_set_names_the_layout_it_was_made_against(): void
    {
        $envelope = $this->draftSet(withText: true);
        $patch = ['field' => ['variable_name' => 'Agreement Date', 'value' => '2026-02-01']];

        $this->patchJson(self::BASE.'/'.$envelope->public_id, $patch, $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'documents_layout_version_required');

        $this->patchJson(self::BASE.'/'.$envelope->public_id, $patch + ['documents_layout_version' => 7], $this->headers)
            ->assertStatus(409)->assertJsonPath('error', 'document_set_version_conflict')->assertJsonPath('details.documents_layout_version', 1);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, $patch + ['documents_layout_version' => 'one'], $this->headers)
            ->assertStatus(400);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, $patch + ['documents_layout_version' => 1], $this->headers)->assertOk();
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * A draft built from two uploads: a three-page master agreement with no fields, then a
     * one-page schedule (absolute page 4) carrying the signature.
     */
    private function draftSet(bool $withText = false): Envelope
    {
        $first = $this->largeUpload('multi-page-mixed-size', 'master-agreement.pdf');
        $second = $this->smallUpload('single-page-letter', 'schedule-a.pdf');

        $body = $this->body(['document_ids' => [$first, $second]], 4);

        if ($withText) {
            $body['fields'][] = ['type' => 'text', 'page_number' => 4, 'variable_name' => 'Agreement Date', 'read_only' => true, 'position' => ['x' => 50.0, 'y' => 20.0, 'width' => 20.0, 'height' => 4.0]];
        }

        $id = $this->postJson(self::BASE, $body, $this->headers)->assertCreated()->json('id');

        return $this->envelope((string) $id);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function body(array $source, int $page = 1): array
    {
        return $source + [
            'name' => 'Synthetic multi-document agreement',
            'recipients' => [
                ['first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test', 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => $page, 'variable_name' => 'Buyer Signature', 'position' => ['x' => 10.0, 'y' => 70.0, 'width' => 30.0, 'height' => 5.0]],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function documentIds(Envelope $envelope): array
    {
        return array_column($this->getJson(self::BASE.'/'.$envelope->public_id, $this->headers)->json('documents'), 'document_id');
    }

    private function envelope(string $publicId): Envelope
    {
        return Envelope::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function requestUpload(int $size): TestResponse
    {
        return $this->postJson(self::API.'/documents', [
            'file_name' => 'upload.pdf',
            'file_size' => $size,
            'content_type' => 'application/pdf',
        ], $this->headers)->assertCreated();
    }

    private function largeUpload(string $fixture, string $fileName): string
    {
        $bytes = PdfFixtures::bytes($fixture);
        $issued = $this->postJson(self::API.'/documents', [
            'file_name' => $fileName,
            'file_size' => strlen($bytes),
            'content_type' => 'application/pdf',
        ], $this->headers)->assertCreated();

        $this->putRaw((string) $issued->json('upload_url'), $bytes)->assertOk();

        return (string) $issued->json('document_id');
    }

    private function smallUpload(string $fixture, string $fileName): string
    {
        return (string) $this->postJson(self::API.'/documents/inline', [
            'file_name' => $fileName,
            'document' => base64_encode(PdfFixtures::bytes($fixture)),
        ], $this->headers)->assertCreated()->json('document_id');
    }

    private function putRaw(string $url, string $bytes, string $type = 'application/pdf'): TestResponse
    {
        return $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => $type, 'CONTENT_LENGTH' => (string) strlen($bytes), 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
