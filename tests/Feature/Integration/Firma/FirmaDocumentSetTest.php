<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\PdfFixtures;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * The read side of a document set on the Firma facade (issue #163): `documents[]` on the
 * polling response, and `GET /signing-requests/{id}/documents/{document_id}/download`.
 *
 * The write side — `POST /documents`, `document_ids`, and editing a draft's set — is not in this
 * build and still answers 501 by name.
 */
class FirmaDocumentSetTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/functions/v1/signing-request-api/signing-requests';

    public function test_a_sets_documents_are_listed_and_downloaded_stored_then_sealed(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $headers = FirmaFacadeScenario::headers($issued);
        [$envelope, $second] = $this->sentSet($scenario);

        $detail = $this->getJson(self::BASE.'/'.$envelope->public_id, $headers)->assertOk();
        $this->assertNull($detail->json('document_url'), 'A set has no single review file.');
        $this->assertSame(4, $detail->json('document_page_count'));
        $this->assertSame([1, 2], array_column($detail->json('documents'), 'position'));
        $this->assertSame(['stored', 'stored'], array_column($detail->json('documents'), 'document_url_kind'));

        $memberId = (string) $detail->json('documents.1.document_id');

        // The member's reviewed revision, before anything is sealed.
        $original = $this->getJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$memberId.'/download', $headers)
            ->assertOk()->assertJsonPath('type', 'original');
        $this->get((string) $original->json('download_url'))->assertOk()
            ->assertStreamedContent(PdfFixtures::bytes('single-page-letter'));

        // Its own sealed copy does not exist until the agreement is complete.
        $this->getJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$memberId.'/download?type=document', $headers)
            ->assertStatus(409)->assertJsonPath('error', 'no_document_available');

        $signing = $scenario->signing;
        $signing->signAs($envelope, $signing->recipient($envelope, 'buyer'), 'session-buyer');
        $signing->signAs($envelope->refresh(), $signing->recipient($envelope, 'seller'), 'session-seller');
        $scenario->finalize($envelope->refresh());

        $sealed = Artifact::query()->where('envelope_id', $envelope->getKey())
            ->where('kind', ArtifactKind::ExecutedDocument->value)->where('slot', 2)->firstOrFail();

        $done = $this->getJson(self::BASE.'/'.$envelope->public_id, $headers)->assertOk();
        $this->assertSame(['signed', 'signed'], array_column($done->json('documents'), 'document_url_kind'));
        $this->assertSame($sealed->sha256, $done->json('documents.1.document_only_hash'));

        $download = $this->getJson(self::BASE.'/'.$envelope->public_id.'/documents/'.$memberId.'/download?type=document', $headers)
            ->assertOk();
        $bytes = $this->get((string) $download->json('download_url'))->assertOk()->streamedContent();
        $this->assertSame($sealed->sha256, hash('sha256', $bytes));
        $this->assertNotSame($second->sha256, hash('sha256', $bytes));
    }

    public function test_an_unknown_document_id_is_not_found(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        [$envelope] = $this->sentSet($scenario);

        $this->getJson(self::BASE.'/'.$envelope->public_id.'/documents/01JSYNTHETICNOSUCHDOCUMENT/download', FirmaFacadeScenario::headers($issued))
            ->assertNotFound();
    }

    public function test_editing_a_set_is_not_in_this_build_and_says_so(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $headers = FirmaFacadeScenario::headers($issued);
        [$envelope] = $this->sentSet($scenario);

        $this->postJson(self::BASE.'/'.$envelope->public_id.'/documents', [], $headers)->assertStatus(501);
        $this->putJson(self::BASE.'/'.$envelope->public_id.'/documents/order', [], $headers)->assertStatus(501);
        $this->postJson('/functions/v1/signing-request-api/documents', [], $headers)->assertStatus(501);
    }

    /**
     * A two-document envelope built through the native path, which this build's facade reads.
     *
     * @return array{0: Envelope, 1: DocumentRevision}
     */
    private function sentSet(FirmaFacadeScenario $scenario): array
    {
        $signing = $scenario->signing;
        $first = $signing->addDocument(PdfFixtures::bytes('multi-page-mixed-size'), 3, 'Synthetic master agreement');
        $second = $signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1, 'Synthetic schedule A');
        $set = DocumentSet::of([
            ['revision_id' => (int) $first->getKey(), 'title' => 'Synthetic master agreement', 'sha256' => $first->sha256, 'page_count' => 3],
            ['revision_id' => (int) $second->getKey(), 'title' => 'Synthetic schedule A', 'sha256' => $second->sha256, 'page_count' => 1],
        ]);

        $envelope = $signing->sent([
            'document_revision_id' => $first->getKey(),
            'document_sha256' => $set->digest(),
            'additional_document_revision_ids' => [$second->getKey()],
            'field_schema' => SigningFixtures::mutateField(SigningFixtures::sequentialTwoSigners(), 'seller_signature', ['page' => 4]),
        ]);

        return [$envelope, $second];
    }
}
