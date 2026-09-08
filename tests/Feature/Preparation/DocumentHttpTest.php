<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentIntake;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentWorkspace;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * The document HTTP surface: upload, metadata, and streamed downloads.
 *
 * Every request in this file carries `Accept: application/json`. Stage 2 has no document UI
 * yet, and an unauthenticated HTML request would be redirected to the `login` route that
 * the SSO work owns; asking for JSON keeps these tests about authorization rather than
 * about which redirect target exists this week.
 */
class DocumentHttpTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

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

    // ---------------------------------------------------------------- upload

    public function test_a_sender_can_upload_a_pdf(): void
    {
        $response = $this->actingAs($this->sender)->post(
            $this->uploadUrl(),
            ['file' => DocumentWorkspace::upload('single-page-letter'), 'title' => 'Mutual NDA'],
            self::JSON,
        );

        $response->assertCreated()
            ->assertJsonPath('title', 'Mutual NDA')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('page_count', 1)
            ->assertJsonPath('original.sha256', hash('sha256', PdfFixtures::bytes('single-page-letter')))
            ->assertJsonPath('original.mime', 'application/pdf')
            ->assertJsonCount(2, 'revisions');

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_revisions', 2);
    }

    public function test_the_metadata_response_never_contains_a_disk_name_or_an_object_path(): void
    {
        $document = $this->uploadDocument('single-page-letter');

        $response = $this->actingAs($this->sender)->get($this->documentUrl($document), self::JSON);

        $response->assertOk();
        $body = $response->getContent();
        $this->assertIsString($body);

        $this->assertStringNotContainsString($document->original_path, $body);
        $this->assertStringNotContainsString('documents/'.$this->workspace->public_id, $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString(storage_path(), $body);

        // What it does carry: the digests, the report, and the revision ids to download.
        $response->assertJsonPath('original.sha256', $document->original_sha256)
            ->assertJsonPath('review_revision_id', $document->reviewRevision()?->public_id)
            ->assertJsonPath('preflight.accepted', true);
    }

    public function test_a_rejected_upload_returns_422_with_the_actionable_preflight_findings(): void
    {
        $response = $this->actingAs($this->sender)->post(
            $this->uploadUrl(),
            ['file' => DocumentWorkspace::upload('encrypted-aes128')],
            self::JSON,
        );

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'encrypted')
            ->assertJsonPath('document.status', 'preflight_failed');

        $this->assertStringContainsString('Remove the password', (string) $response->getContent());
        $this->assertNull(Document::query()->sole()->reviewRevision());
    }

    public function test_a_file_that_is_not_a_pdf_is_refused_by_the_form_request_before_any_parsing(): void
    {
        $response = $this->actingAs($this->sender)->post(
            $this->uploadUrl(),
            ['file' => DocumentWorkspace::uploadOfBytes('plain text pretending to be a contract', 'nda.pdf')],
            self::JSON,
        );

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('Only PDF files can be uploaded', (string) $response->getContent());
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_an_oversized_upload_is_refused_by_the_form_request(): void
    {
        config()->set('esign.documents.max_bytes', 2048);

        $response = $this->actingAs($this->sender)->post(
            $this->uploadUrl(),
            ['file' => DocumentWorkspace::uploadOfBytes(
                "%PDF-1.7\n".str_repeat('0', 8192),
                'big.pdf',
            )],
            self::JSON,
        );

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertStringContainsString('upload limit', (string) $response->getContent());
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->actingAs($this->sender)
            ->post($this->uploadUrl(), [], self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    // --------------------------------------------------------- authorization

    public function test_an_auditor_may_read_a_document_but_may_not_upload_one(): void
    {
        $document = $this->uploadDocument('single-page-letter');
        $auditor = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Auditor);

        $this->actingAs($auditor)
            ->get($this->documentUrl($document), self::JSON)
            ->assertOk();

        $this->actingAs($auditor)
            ->post($this->uploadUrl(), ['file' => DocumentWorkspace::upload('single-page-letter')], self::JSON)
            ->assertForbidden();
    }

    public function test_an_unauthenticated_caller_is_denied_on_every_route(): void
    {
        $document = $this->uploadDocument('single-page-letter');
        $revision = $document->reviewRevision();
        $this->assertNotNull($revision);

        $this->post($this->uploadUrl(), ['file' => DocumentWorkspace::upload('single-page-letter')], self::JSON)
            ->assertUnauthorized();
        $this->get($this->documentUrl($document), self::JSON)->assertUnauthorized();
        $this->get($this->documentUrl($document).'/revisions/'.$revision->public_id.'/download', self::JSON)
            ->assertUnauthorized();
        $this->get($this->documentUrl($document).'/revisions/'.$revision->public_id.'/view', self::JSON)
            ->assertUnauthorized();
    }

    public function test_a_member_of_another_workspace_cannot_reach_this_one_by_public_id(): void
    {
        $document = $this->uploadDocument('single-page-letter');

        $other = Workspace::factory()->create();
        $outsider = DocumentWorkspace::memberOf($other, WorkspaceRole::Owner);

        // The workspace is a 404 rather than a 403: an outsider must not be able to tell a
        // workspace they cannot see from one that does not exist.
        $this->actingAs($outsider)->get($this->documentUrl($document), self::JSON)->assertNotFound();
        $this->actingAs($outsider)
            ->post($this->uploadUrl(), ['file' => DocumentWorkspace::upload('single-page-letter')], self::JSON)
            ->assertNotFound();
    }

    public function test_a_document_from_another_workspace_is_a_404_inside_a_workspace_the_caller_can_see(): void
    {
        $other = Workspace::factory()->create();
        $otherSender = DocumentWorkspace::memberOf($other, WorkspaceRole::Sender);
        $otherDocument = app(DocumentIntake::class)->intake(
            $other,
            $otherSender,
            DocumentWorkspace::upload('single-page-letter'),
        );

        // A valid document ULID, presented under a workspace the caller *is* a member of.
        $this->actingAs($this->sender)
            ->get('/workspaces/'.$this->workspace->public_id.'/documents/'.$otherDocument->public_id, self::JSON)
            ->assertNotFound();
    }

    public function test_a_revision_from_another_document_is_a_404(): void
    {
        $mine = $this->uploadDocument('single-page-letter');
        $theirs = $this->uploadDocument('multi-page-mixed-size');
        $theirRevision = $theirs->reviewRevision();
        $this->assertNotNull($theirRevision);

        $this->actingAs($this->sender)
            ->get($this->documentUrl($mine).'/revisions/'.$theirRevision->public_id.'/download', self::JSON)
            ->assertNotFound();
    }

    public function test_an_unknown_or_malformed_identifier_is_a_404(): void
    {
        $document = $this->uploadDocument('single-page-letter');

        $this->actingAs($this->sender)
            ->get('/workspaces/'.$this->workspace->public_id.'/documents/'.Str::ulid(), self::JSON)
            ->assertNotFound();

        // Not a ULID at all: refused by the route constraint before a query runs.
        $this->actingAs($this->sender)
            ->get('/workspaces/'.$this->workspace->public_id.'/documents/1', self::JSON)
            ->assertNotFound();
        $this->actingAs($this->sender)
            ->get('/workspaces/'.$this->workspace->getKey().'/documents/'.$document->public_id, self::JSON)
            ->assertNotFound();
    }

    // -------------------------------------------------------------- download

    public function test_a_download_streams_the_exact_stored_bytes_as_an_attachment(): void
    {
        $document = $this->uploadDocument('multi-page-mixed-size', 'Mutual NDA');
        $revision = $document->originalRevision();
        $this->assertNotNull($revision);

        $response = $this->actingAs($this->sender)
            ->get($this->documentUrl($document).'/revisions/'.$revision->public_id.'/download');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Document-Sha256', $revision->sha256);

        $this->assertStringContainsString(
            'attachment; filename=',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            'mutual-nda-original-'.substr($revision->sha256, 0, 12).'.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );

        $bytes = $response->streamedContent();
        $this->assertSame(PdfFixtures::bytes('multi-page-mixed-size'), $bytes);
        $this->assertSame($revision->sha256, hash('sha256', $bytes));
    }

    public function test_the_view_route_serves_the_same_bytes_inline_for_the_pdf_viewer(): void
    {
        $document = $this->uploadDocument('single-page-letter');
        $revision = $document->reviewRevision();
        $this->assertNotNull($revision);

        $response = $this->actingAs($this->sender)
            ->get($this->documentUrl($document).'/revisions/'.$revision->public_id.'/view');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(PdfFixtures::bytes('single-page-letter'), $response->streamedContent());
    }

    public function test_a_hostile_document_title_cannot_escape_the_content_disposition_header(): void
    {
        $document = $this->uploadDocument('single-page-letter', "../../etc/passwd\"; rm -rf /\r\nX-Injected: 1");
        $revision = $document->originalRevision();
        $this->assertNotNull($revision);

        $response = $this->actingAs($this->sender)
            ->get($this->documentUrl($document).'/revisions/'.$revision->public_id.'/download');

        $disposition = (string) $response->headers->get('Content-Disposition');

        $response->assertOk();
        $this->assertNull($response->headers->get('X-Injected'));
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertMatchesRegularExpression('/^attachment; filename="?[A-Za-z0-9._-]+"?$/', $disposition);
    }

    public function test_an_outsider_cannot_download_another_workspaces_revision(): void
    {
        $document = $this->uploadDocument('single-page-letter');
        $revision = $document->originalRevision();
        $this->assertNotNull($revision);

        $other = Workspace::factory()->create();
        $outsider = DocumentWorkspace::memberOf($other, WorkspaceRole::Owner);

        $this->actingAs($outsider)
            ->get($this->documentUrl($document).'/revisions/'.$revision->public_id.'/download', self::JSON)
            ->assertNotFound();
    }

    /**
     * docs/security/review-2026-09.md finding U-5.
     *
     * The original is stored before the accept/reject decision, deliberately, so a rejected
     * upload can be examined afterwards — and the 422 hands the uploader the document and
     * revision ids. So the uploader got the exact inline URL for bytes preflight had just
     * called unsafe, and any workspace member with `View` would render them inline from this
     * application's own origin. The attachment route still serves them, which is what
     * examining a rejected file actually needs.
     */
    public function test_a_rejected_original_is_never_rendered_inline(): void
    {
        $this->actingAs($this->sender)->post(
            $this->uploadUrl(),
            ['file' => DocumentWorkspace::upload('javascript-action')],
            self::JSON,
        )->assertStatus(422);

        $document = Document::query()->sole();
        $this->assertSame(DocumentStatus::PreflightFailed, $document->status);

        $revision = $document->revisions()->sole();
        $base = $this->documentUrl($document).'/revisions/'.$revision->public_id;

        $this->actingAs($this->sender)->get($base.'/view')->assertNotFound();

        $download = $this->actingAs($this->sender)->get($base.'/download');
        $download->assertOk();
        $this->assertStringStartsWith(
            'attachment;',
            (string) $download->headers->get('Content-Disposition'),
        );
    }

    /**
     * docs/security/review-2026-09.md findings U-1 and U-2, which are open: the preflight
     * parser's decompression ceiling is per stream rather than aggregate, and `max_objects`
     * is checked after the parse. Both are reachable from one synchronous request. Until the
     * parser grows an aggregate budget, this ceiling is what bounds how often one member can
     * trigger it.
     */
    public function test_uploading_is_rate_limited_per_member(): void
    {
        config()->set('esign.documents.max_bytes', 2048);

        $refused = null;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $response = $this->actingAs($this->sender)->post(
                $this->uploadUrl(),
                ['file' => DocumentWorkspace::uploadOfBytes('not a pdf', 'nda.pdf')],
                self::JSON,
            );

            if ($response->getStatusCode() === 429) {
                $refused = $response;

                break;
            }
        }

        $this->assertNotNull($refused, 'The upload route must have a ceiling.');
        $refused->assertHeader('Retry-After');
    }

    // ---------------------------------------------------------------- helpers

    private function uploadUrl(): string
    {
        return '/workspaces/'.$this->workspace->public_id.'/documents';
    }

    private function documentUrl(Document $document): string
    {
        return $this->uploadUrl().'/'.$document->public_id;
    }

    private function uploadDocument(string $fixture, ?string $title = null): Document
    {
        return app(DocumentIntake::class)->intake(
            $this->workspace,
            $this->sender,
            DocumentWorkspace::upload($fixture),
            $title,
        );
    }
}
