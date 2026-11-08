<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Contracts\ArtifactValidator;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Evidence\Finalization\Exceptions\FinalizationException;
use App\Domain\Preparation\Documents\DocumentSet;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeDocument;
use App\Domain\Signing\Models\RecipientAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FinalizationScenario;
use Tests\Support\PdfFixtures;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * A document set taken from creation through sealing (issue #163,
 * docs/preparation/document-sets.md).
 *
 * Two real documents — three pages and one — with a field on the second document's page (page 4
 * of the set). The acceptance binds the set digest; finalization publishes the combined executed
 * PDF and each document sealed on its own, every one validated; the evidence names them all.
 */
class DocumentSetFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_two_document_set_is_bound_signed_and_sealed_whole_and_per_document(): void
    {
        [$scenario, $envelope] = $this->signedSet();

        $set = $envelope->documentSet();
        $this->assertTrue($set->isMultiple());
        $this->assertSame(4, $set->totalPages());
        $this->assertSame([1, 4], array_map(static fn ($entry): int => $entry->firstPage, $set->entries));
        $this->assertSame($set->digest(), $envelope->document_sha256);

        // Every acceptance is bound to the whole set, not to its first document.
        foreach (RecipientAttestation::query()->where('envelope_id', $envelope->getKey())->get() as $attestation) {
            $this->assertSame($set->digest(), $attestation->document_sha256);
        }

        app(EnvelopeFinalizer::class)->finalize($envelope);
        $this->assertSame(EnvelopeState::Completed, $envelope->refresh()->state);

        $artifacts = Artifact::query()->where('envelope_id', $envelope->getKey())->orderBy('kind')->orderBy('slot')->get();
        $this->assertSame(
            ['completion_report:0', 'evidence_json:0', 'executed_document:1', 'executed_document:2', 'executed_pdf:0'],
            $artifacts->map(fn (Artifact $a): string => $a->kind->value.':'.$a->slot)->all(),
        );

        $validator = app(ArtifactValidator::class);
        $pageCounts = [];

        foreach ($artifacts->filter(fn (Artifact $a): bool => $a->kind->isSealed()) as $artifact) {
            $bytes = (string) Storage::disk('documents')->get($artifact->path);
            $this->assertTrue($validator->validate($bytes)->isValid(), $artifact->kind->value.':'.$artifact->slot.' validates');
            $pageCounts[$artifact->kind->value.':'.$artifact->slot] = preg_match_all('/\/Type\s*\/Page[^s]/', $bytes);
        }

        // Each document's own pages plus the completion report; the whole set plus the report.
        $this->assertSame(['executed_document:1' => 4, 'executed_document:2' => 2, 'executed_pdf:0' => 5], array_intersect_key($pageCounts, ['executed_document:1' => 1, 'executed_document:2' => 1, 'executed_pdf:0' => 1]));

        $evidence = json_decode((string) Storage::disk('documents')->get(
            $artifacts->first(fn (Artifact $a): bool => $a->kind === ArtifactKind::EvidenceJson)->path,
        ), true);

        $this->assertSame(DocumentSet::ENCODING, $evidence['document_set']['encoding']);
        $this->assertSame($set->digest(), $evidence['document_set']['sha256']);
        $this->assertSame([1, 2], array_column($evidence['document_set']['documents'], 'position'));

        foreach ($evidence['document_set']['documents'] as $member) {
            $published = $artifacts->first(fn (Artifact $a): bool => $a->kind === ArtifactKind::ExecutedDocument && $a->slot === $member['position']);
            $this->assertSame($published->sha256, $member['executed_document_sha256']);
            $this->assertSame('pades-b-b', $member['executed_document_assurance_level_reached']);
        }
    }

    public function test_a_member_that_no_longer_hashes_to_the_set_publishes_nothing(): void
    {
        [, $envelope] = $this->signedSet();
        $member = EnvelopeDocument::query()->where('envelope_id', $envelope->getKey())->where('position', 2)->firstOrFail();
        $revision = $member->documentRevision;

        Storage::disk('documents')->put($revision->path, PdfFixtures::bytes('multi-page-mixed-size'));

        try {
            app(EnvelopeFinalizer::class)->finalize($envelope);
            $this->fail('A set member that no longer hashes to its digest must not be published.');
        } catch (FinalizationException $failure) {
            $this->assertStringContainsString('Document 2 of the set', $failure->getMessage());
        }

        $this->assertSame(EnvelopeState::FinalizationFailed, $envelope->refresh()->state);
        $this->assertSame(0, Artifact::query()->where('envelope_id', $envelope->getKey())->count());
    }

    public function test_a_set_is_refused_when_its_declared_digest_is_not_the_set_digest(): void
    {
        $scenario = SigningScenario::create()->bind();
        $first = $scenario->addDocument(PdfFixtures::bytes('multi-page-mixed-size'), 3, 'Synthetic master agreement');
        $second = $scenario->addDocument(PdfFixtures::bytes('single-page-letter'), 1, 'Synthetic schedule A');

        $this->expectException(InvalidEnvelopeSnapshot::class);

        // Declaring the first document's own digest would bind acceptances to one document of two.
        $scenario->draft([
            'document_revision_id' => $first->getKey(),
            'document_sha256' => $first->sha256,
            'additional_document_revision_ids' => [$second->getKey()],
        ]);
    }

    /**
     * @return array{0: SigningScenario, 1: Envelope}
     */
    private function signedSet(): array
    {
        Storage::fake('documents');
        FinalizationScenario::configureSeal();

        $scenario = SigningScenario::create()->bind();
        $first = $scenario->addDocument(PdfFixtures::bytes('multi-page-mixed-size'), 3, 'Synthetic master agreement');
        $second = $scenario->addDocument(PdfFixtures::bytes('single-page-letter'), 1, 'Synthetic schedule A');

        $set = DocumentSet::of([
            ['revision_id' => (int) $first->getKey(), 'title' => 'Synthetic master agreement', 'sha256' => $first->sha256, 'page_count' => 3],
            ['revision_id' => (int) $second->getKey(), 'title' => 'Synthetic schedule A', 'sha256' => $second->sha256, 'page_count' => 1],
        ]);

        // The seller signs on the second document: page 4 of the set.
        $schema = SigningFixtures::mutateField(SigningFixtures::sequentialTwoSigners(), 'seller_signature', ['page' => 4]);

        $envelope = $scenario->sent([
            'document_revision_id' => $first->getKey(),
            'document_sha256' => $set->digest(),
            'additional_document_revision_ids' => [$second->getKey()],
            'field_schema' => $schema,
        ]);

        $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'), 'session-buyer');
        $scenario->signAs($envelope->refresh(), $scenario->recipient($envelope, 'seller'), 'session-seller');

        return [$scenario, $envelope->refresh()];
    }
}
