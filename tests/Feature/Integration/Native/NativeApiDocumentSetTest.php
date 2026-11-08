<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NativeApiScenario;
use Tests\Support\PdfFixtures;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * `document_ids` on the native API (issue #163, docs/preparation/document-sets.md).
 */
class NativeApiDocumentSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_envelope_is_created_from_a_document_set_and_reads_back_its_documents(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $first = $scenario->signing->addDocument(PdfFixtures::bytes('multi-page-mixed-size'), 3, 'Synthetic master agreement');
        $second = $scenario->signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1, 'Synthetic schedule A');

        $response = $this->postJson('/api/v1/envelopes', [
            'document_ids' => [$first->document->public_id, $second->document->public_id],
            // The seller signs on the second document: page 4 of the set.
            'field_schema' => SigningFixtures::mutateField(SigningFixtures::sequentialTwoSigners(), 'seller_signature', ['page' => 4]),
            'title' => 'Synthetic agreement with a schedule',
        ], NativeApiScenario::headers($issued))->assertCreated();

        $expected = DocumentSet::of([
            ['revision_id' => (int) $first->getKey(), 'title' => '', 'sha256' => $first->sha256, 'page_count' => 3],
            ['revision_id' => (int) $second->getKey(), 'title' => '', 'sha256' => $second->sha256, 'page_count' => 1],
        ])->digest();

        $response->assertJsonPath('document_sha256', $expected)
            ->assertJsonPath('documents.0.title', 'Synthetic master agreement')
            ->assertJsonPath('documents.0.first_page', 1)
            ->assertJsonPath('documents.1.first_page', 4)
            ->assertJsonPath('documents.1.sha256', $second->sha256);
        $this->assertNotNull($response->json('documents.1.id'));

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertSame(2, $envelope->documentSet()->count());
    }

    public function test_a_single_document_envelope_reads_back_one_document_without_a_set_id(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $this->postJson('/api/v1/envelopes', [
            'document_id' => $scenario->signing->document->public_id,
            'field_schema' => SigningFixtures::singleSigner(),
        ], NativeApiScenario::headers($issued))
            ->assertCreated()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', null)
            ->assertJsonPath('documents.0.sha256', $scenario->signing->revision->sha256);
    }

    public function test_a_field_past_the_last_page_of_the_set_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $first = $scenario->signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1, 'Synthetic one');
        $second = $scenario->signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1, 'Synthetic two');

        $this->postJson('/api/v1/envelopes', [
            'document_ids' => [$first->document->public_id, $second->document->public_id],
            'field_schema' => SigningFixtures::mutateField(SigningFixtures::singleSigner(), 'signer_signature', ['page' => 3]),
        ], NativeApiScenario::headers($issued))->assertStatus(422);

        $this->assertSame(0, Envelope::query()->count());
    }

    public function test_document_ids_and_document_id_together_are_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $other = $scenario->signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1);

        $this->postJson('/api/v1/envelopes', [
            'document_id' => $scenario->signing->document->public_id,
            'document_ids' => [$scenario->signing->document->public_id, $other->document->public_id],
            'field_schema' => SigningFixtures::singleSigner(),
        ], NativeApiScenario::headers($issued))->assertStatus(422);
    }

    public function test_a_document_from_another_workspace_is_absent_from_the_set(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $own = $scenario->signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1);
        $foreign = NativeApiScenario::create()->signing->addDocument(PdfFixtures::bytes('single-page-letter'), 1);

        $this->postJson('/api/v1/envelopes', [
            'document_ids' => [$own->document->public_id, $foreign->document->public_id],
            'field_schema' => SigningFixtures::singleSigner(),
        ], NativeApiScenario::headers($issued))->assertNotFound();
    }
}
