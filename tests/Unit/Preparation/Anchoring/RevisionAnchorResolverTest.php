<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Anchoring;

use App\Domain\Preparation\Anchoring\AnchorResolutionFailed;
use App\Domain\Preparation\Anchoring\RevisionAnchorResolver;
use App\Domain\Preparation\Anchoring\SchemaAnchorResolver;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Documents\Models\DocumentRevision;
use App\Domain\Preparation\Documents\RevisionBytes;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\SchemaVersion;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\AnchorResolver;
use App\Domain\Preparation\Text\TextRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * Reading one document is one budget, and crossing it says the same thing wherever it happens.
 *
 * Extraction and matching are two phases with one cost. Extraction's ceiling stops when
 * extraction returns; matching begins there and scans every run once per anchored field, and a
 * field set has no length limit — so a document inside every preflight ceiling can still cost
 * runs x fields after the ceiling has stopped applying.
 *
 * What this pins is the pair. A ceiling crossed in either phase reaches the sender as the same
 * per-field `anchor_text_unreadable`, because from their side both are "this document could not
 * be read"; and neither reaches them as a `PreflightBudgetException`, which is a 500. The two
 * phases are handled in two different places — the inner catch around extraction, the outer one
 * around matching — so a test of one passes while the other is missing, which is exactly how the
 * second one came to be missing.
 */
final class RevisionAnchorResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function phases(): iterable
    {
        // Whether extraction is real (and therefore charges the budget), and whether the budget
        // has room. A fake locator hands back runs without spending anything, so the first charge
        // is the one matching makes — which is the only way to reach the second phase's guard.
        yield 'the budget survives both phases' => [true, true];
        yield 'extraction crosses the ceiling' => [true, false];
        yield 'matching crosses the ceiling' => [false, false];
    }

    #[DataProvider('phases')]
    public function test_a_ceiling_crossed_in_either_phase_is_reported_as_an_unreadable_document(
        bool $realExtraction,
        bool $hasRoom,
    ): void {
        $bytes = PdfFixtures::bytes('single-page-letter');
        $revision = $this->revision($bytes);
        $runs = (new TcPdfTextLocator)->extract($bytes);

        $limits = new PreflightLimits(timeBudgetSeconds: $hasRoom ? 3600.0 : 0.0000001);

        if (! $hasRoom) {
            usleep(1000);
        }

        $resolver = new RevisionAnchorResolver(
            $realExtraction ? new TcPdfTextLocator : $this->locatorReturning($runs),
            app(RevisionBytes::class),
            new SchemaAnchorResolver(new AnchorResolver),
            app(PdfPreflight::class),
            $limits,
        );

        if ($hasRoom) {
            $outcome = $resolver->resolve($revision, $this->anchoredDocument());

            $this->assertSame(['signature'], $outcome->resolved);

            return;
        }

        try {
            $resolver->resolve($revision, $this->anchoredDocument());
            $this->fail('Expected the ceiling to refuse this document.');
        } catch (AnchorResolutionFailed $failed) {
            // One problem per anchored field, and the stable code — never the parser's or the
            // budget's own message, which name limits and engine internals.
            $this->assertCount(1, $failed->problems);
            $this->assertSame(ValidationCode::AnchorTextUnreadable, $failed->problems[0]->code);
            $this->assertSame('signature', $failed->problems[0]->fieldId);
        }
    }

    /** A locator that answers from bytes already read, so extraction costs the budget nothing. */
    private function locatorReturning(array $runs): PdfTextLocator
    {
        return new class($runs) implements PdfTextLocator
        {
            /** @param array<int, TextRun> $runs */
            public function __construct(private readonly array $runs) {}

            public function extract(string $pdfBytes, ?int $page = null, ?PreflightBudget $budget = null): array
            {
                return $this->runs;
            }
        };
    }

    private function anchoredDocument(): FieldSchemaDocument
    {
        return FieldSchemaDocument::fromArray([
            'schema_version' => SchemaVersion::CURRENT,
            'document_id' => 'doc_revision_budget',
            'coordinate_space' => [
                'unit' => 'pt',
                'origin' => 'top-left',
                'page_box' => 'crop',
                'rotation' => 'displayed',
                'page_index_base' => 1,
            ],
            'recipients' => [
                ['id' => 'signer', 'name' => 'Example Signer', 'email' => 'signer@example.test'],
            ],
            'signing_order' => [['signer']],
            'fields' => [[
                'id' => 'signature',
                'recipient_id' => 'signer',
                'type' => 'signature',
                'page' => 1,
                'rect' => ['x' => 1, 'y' => 1, 'width' => 170, 'height' => 36],
                'required' => true,
                'read_only' => false,
                'anchor' => [
                    'text' => 'Signature:',
                    'occurrence' => 'sole',
                    'placement' => AnchorPlacementMode::Replace->value,
                ],
            ]],
        ]);
    }

    private function revision(string $bytes): DocumentRevision
    {
        $document = Document::factory()->create();
        $path = 'documents/'.$document->public_id.'/review.pdf';

        Storage::disk('documents')->put($path, $bytes);

        return DocumentRevision::query()->create([
            'document_id' => $document->getKey(),
            'kind' => RevisionKind::Review,
            'disk' => 'documents',
            'path' => $path,
            'sha256' => hash('sha256', $bytes),
            'bytes' => strlen($bytes),
            'page_count' => 1,
            'normalization' => ['applied' => false],
        ]);
    }
}
