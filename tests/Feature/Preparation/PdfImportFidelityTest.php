<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\UnsupportedSourceException;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\TextRun;
use Com\Tecnick\Pdf\Tcpdf;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledGeometryProbe;
use Tests\Support\PdfFixtures;

/**
 * Import fidelity for issue #5.
 *
 * The check is analytic, not visual. Each source page carries a filled probe square at
 * a known native coordinate. After import the probe is located by composing the form
 * XObject /Matrix with the placement CTM, and a test overlay is drawn at a second known
 * native coordinate. Both must come back at the coordinates they went in at. No
 * rasteriser is used, so the suite runs anywhere PHP does.
 */
final class PdfImportFidelityTest extends TestCase
{
    private const TOLERANCE = 1.0e-4;

    /**
     * Runs compared at 1/1000 pt: matrix composition reorders the same multiplications
     * either side of import, which moves the last bit of a double without moving the ink.
     *
     * @return array<string, mixed>
     */
    private static function roundRun(TextRun $run): array
    {
        return [
            'page' => $run->page,
            'text' => $run->text,
            'rect' => array_map(static fn (float $v): float => round($v, 3), $run->rect->toArray()),
            'direction' => $run->direction->value,
        ];
    }

    /** @return array<int, OverlayRectangle> */
    private static function overlaysFor(array $entry): array
    {
        $overlays = [];
        foreach ($entry['pages'] as $page) {
            [$x, $y, $width, $height] = $page['overlay_native'];
            $overlays[] = new OverlayRectangle(
                $page['page'],
                new NativeRect((float) $x, (float) $y, (float) $width, (float) $height),
            );
        }

        return $overlays;
    }

    #[DataProviderExternal(PdfFixtures::class, 'accepted')]
    public function test_an_overlay_lands_on_the_native_coordinate_it_was_given(string $name, array $entry): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes($name), self::overlaysFor($entry));
        $probe = new AssembledGeometryProbe($assembled->bytes);

        foreach ($entry['pages'] as $page) {
            [$x, $y, $width, $height] = $page['overlay_native'];
            $expected = new NativeRect((float) $x, (float) $y, (float) $width, (float) $height);

            $found = $probe->overlayRects($page['page']);
            $this->assertCount(1, $found, $name.' page '.$page['page'].' should carry exactly one overlay.');
            $this->assertTrue(
                $expected->equals($found[0], self::TOLERANCE),
                $name.' page '.$page['page'].': overlay expected ['.implode(' ', $expected->toArray())
                .'] but landed at ['.implode(' ', $found[0]->toArray()).'].',
            );
        }
    }

    #[DataProviderExternal(PdfFixtures::class, 'accepted')]
    public function test_imported_page_content_keeps_its_native_position(string $name, array $entry): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes($name));
        $probe = new AssembledGeometryProbe($assembled->bytes);

        foreach ($entry['pages'] as $page) {
            [$x, $y, $width, $height] = $page['probe_native'];
            $expected = new NativeRect((float) $x, (float) $y, (float) $width, (float) $height);

            $found = $probe->importedFillRects($page['page']);
            $this->assertNotEmpty($found, $name.' page '.$page['page'].': the source probe square was not found.');
            $this->assertTrue(
                $expected->equals($found[0], self::TOLERANCE),
                $name.' page '.$page['page'].': the source probe was at ['.implode(' ', $expected->toArray())
                .'] but is at ['.implode(' ', $found[0]->toArray()).'] after import.',
            );
        }
    }

    #[DataProviderExternal(PdfFixtures::class, 'accepted')]
    public function test_the_output_page_is_the_displayed_size_with_no_residual_rotation(string $name, array $entry): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes($name));

        $this->assertCount(count($entry['pages']), $assembled->outputPages, $name.' lost or gained pages.');

        foreach ($entry['pages'] as $index => $page) {
            $output = $assembled->outputPages[$index];

            $this->assertSame(0, $output->rotation->value, $name.': rotation must be baked into the content.');
            $this->assertEqualsWithDelta($page['native_width'], $output->nativeWidth(), self::TOLERANCE);
            $this->assertEqualsWithDelta($page['native_height'], $output->nativeHeight(), self::TOLERANCE);
            $this->assertSame(0.0, $output->cropBox->x0);
            $this->assertSame(0.0, $output->cropBox->y0);
        }
    }

    #[DataProviderExternal(PdfFixtures::class, 'accepted')]
    public function test_visible_text_is_neither_lost_nor_reflowed(string $name, array $entry): void
    {
        $locator = new TcPdfTextLocator;
        $source = PdfFixtures::bytes($name);

        $before = $locator->extract($source);
        $after = $locator->extract((new TcPdfAssembler)->assemble($source)->bytes);

        $this->assertSame(
            array_map(self::roundRun(...), $before),
            array_map(self::roundRun(...), $after),
            $name.': text runs changed position or content across import.',
        );

        // The manifest records what the generator drew, independently of the extractor.
        $expected = [];
        foreach ($entry['pages'] as $page) {
            foreach ($page['text_runs'] as $run) {
                $expected[] = $run['text'];
            }
        }
        $this->assertSame($expected, array_map(static fn ($r): string => $r->text, $before), $name.': text mismatch.');
    }

    public function test_text_run_rectangles_match_the_manifest(): void
    {
        $locator = new TcPdfTextLocator;

        foreach (PdfFixtures::manifest() as $entry) {
            if ($entry['expected_preflight'] !== 'accept') {
                continue;
            }

            $runs = $locator->extract(PdfFixtures::bytes($entry['name']));
            $index = 0;

            foreach ($entry['pages'] as $page) {
                foreach ($page['text_runs'] as $expectedRun) {
                    $run = $runs[$index++];
                    [$x, $y, $width, $height] = $expectedRun['native_rect'];

                    $this->assertTrue(
                        $run->rect->equals(new NativeRect((float) $x, (float) $y, (float) $width, (float) $height), 1.0e-3),
                        $entry['name'].' run "'.$expectedRun['text'].'" expected ['
                        .implode(' ', array_map(static fn ($v): string => (string) $v, $expectedRun['native_rect']))
                        .'] but was ['.implode(' ', $run->rect->toArray()).'].',
                    );
                }
            }
        }
    }

    public function test_assembly_refuses_every_document_preflight_rejects(): void
    {
        $assembler = new TcPdfAssembler;

        foreach (PdfFixtures::manifest() as $entry) {
            if ($entry['expected_preflight'] !== 'reject') {
                continue;
            }

            try {
                $assembler->assemble(PdfFixtures::bytes($entry['name']));
                $this->fail($entry['name'].' was assembled even though preflight rejects it.');
            } catch (UnsupportedSourceException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_the_engine_cannot_import_an_encrypted_document_even_with_preflight_disabled(): void
    {
        $this->expectException(UnsupportedSourceException::class);
        $this->expectExceptionMessage('encrypted');

        (new TcPdfAssembler(null))->assemble(PdfFixtures::bytes('encrypted-aes128'));
    }

    /**
     * Documents the engine defect that TcPdfAssembler compensates for.
     *
     * tc-lib-pdf 8.73.6 derives the imported form XObject /Matrix from the page box
     * width and height and ignores its origin, so an unassisted import of a page whose
     * CropBox starts at (36, 48) emits an identity matrix and displaces the content by
     * the box origin. If a future release fixes this, this test fails and the
     * compensation in TcPdfAssembler::boxOriginCompensation() must be removed.
     */
    public function test_the_engine_still_ignores_a_nonzero_page_box_origin(): void
    {
        $pdf = new Tcpdf('pt', true, false, false);
        $sourceId = $pdf->setImportSourceData(PdfFixtures::bytes('cropbox-offset'));
        $pdf->addPageFromImport($sourceId, 1, ['box' => 'CropBox', 'respectRotation' => true]);

        $probe = new AssembledGeometryProbe($pdf->getOutPDFString());

        $this->assertSame(
            [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
            $probe->firstFormMatrix(1),
            'The engine now emits a translation for the CropBox origin; drop the compensation.',
        );

        // The probe was drawn at native (40, 40) on a CropBox with origin (36, 48).
        // Uncompensated, the content keeps its source user-space coordinates, so it moves
        // 36 pt right and 48 pt up: native x becomes 76 and native y becomes -8, i.e. the
        // top of the page is clipped away.
        $found = $probe->importedFillRects(1);
        $this->assertNotEmpty($found);
        $this->assertEqualsWithDelta(76.0, $found[0]->x, self::TOLERANCE);
        $this->assertEqualsWithDelta(-8.0, $found[0]->y, self::TOLERANCE);
    }

    public function test_assembly_reports_source_and_output_geometry_separately(): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes('rotated-pages'));

        $this->assertSame([90, 180, 270], array_map(static fn ($p): int => $p->rotation->value, $assembled->sourcePages));
        $this->assertSame([0, 0, 0], array_map(static fn ($p): int => $p->rotation->value, $assembled->outputPages));
        $this->assertGreaterThan(0.0, $assembled->elapsedSeconds);
    }

    public function test_user_unit_is_not_carried_into_the_assembled_document(): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes('user-unit'));

        $this->assertSame(2.0, $assembled->sourcePages[0]->userUnit);
        $this->assertSame(
            1.0,
            $assembled->outputPages[0]->userUnit,
            'If the engine starts preserving /UserUnit, the preflight warning can be dropped.',
        );
    }

    public function test_annotations_are_not_carried_into_the_assembled_document(): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes('form-fields-annotations'));

        $this->assertStringNotContainsString('/Widget', $assembled->bytes);
        $this->assertStringNotContainsString('/AcroForm', $assembled->bytes);
    }

    public function test_the_embedded_font_program_survives_import(): void
    {
        $assembled = (new TcPdfAssembler)->assemble(PdfFixtures::bytes('unicode-embedded-font'));

        $this->assertStringContainsString('/FontFile2', $assembled->bytes);
        $this->assertStringContainsString('Identity-H', $assembled->bytes);
    }
}
