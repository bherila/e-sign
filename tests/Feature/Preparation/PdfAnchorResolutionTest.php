<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use App\Domain\Preparation\Text\AmbiguousAnchorException;
use App\Domain\Preparation\Text\Anchor;
use App\Domain\Preparation\Text\AnchorNotFoundException;
use App\Domain\Preparation\Text\AnchorOccurrence;
use App\Domain\Preparation\Text\AnchorResolver;
use App\Domain\Preparation\Text\ResolvedAnchor;
use App\Domain\Preparation\Text\TextDirection;
use App\Domain\Preparation\Text\TextExtractionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PdfFixtures;

/**
 * Anchor resolution over the fixture matrix for issue #6.
 *
 * Covers, at minimum: a plain page, a multi-page document, a rotated page, a page with
 * a nonzero CropBox origin, a page with several occurrences of the anchor string, a
 * page whose text is drawn with an embedded Identity-H font, and a scanned-image page.
 */
final class PdfAnchorResolutionTest extends TestCase
{
    /** @return array<string, array{string, int}> Fixture name => expected "Signature:" match count. */
    public static function fixturesWithASignatureAnchor(): array
    {
        return [
            'single page' => ['single-page-letter', 1],
            'rotated pages' => ['rotated-pages', 3],
            'offset crop box' => ['cropbox-offset', 2],
            'multiple occurrences' => ['multi-occurrence', 3],
            'embedded unicode font' => ['unicode-embedded-font', 1],
            'scanned image page' => ['scanned-image-page', 1],
            'cross-reference stream' => ['xref-stream', 1],
            'object stream' => ['object-stream', 1],
        ];
    }

    #[DataProvider('fixturesWithASignatureAnchor')]
    public function test_the_anchor_string_is_found_the_expected_number_of_times(string $fixture, int $expected): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes($fixture));
        $anchor = new Anchor('Signature:', AnchorOccurrence::all(), width: 170.0, height: 36.0);

        $this->assertCount($expected, (new AnchorResolver)->resolve($runs, $anchor));
    }

    #[DataProvider('fixturesWithASignatureAnchor')]
    public function test_resolution_is_byte_stable_across_runs(string $fixture, int $expected): void
    {
        $locator = new TcPdfTextLocator;
        $resolver = new AnchorResolver;
        $anchor = new Anchor('Signature:', AnchorOccurrence::all(), offsetY: 16.0, width: 170.0, height: 36.0);

        $first = $resolver->resolve($locator->extract(PdfFixtures::bytes($fixture)), $anchor);
        $second = $resolver->resolve($locator->extract(PdfFixtures::bytes($fixture)), $anchor);

        $this->assertSame(
            array_map(static fn ($r): array => $r->toArray(), $first),
            array_map(static fn ($r): array => $r->toArray(), $second),
        );
        $this->assertCount($expected, $first);
    }

    public function test_an_anchor_resolves_to_the_rectangle_the_manifest_predicts(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));
        $anchor = new Anchor('Signature:', AnchorOccurrence::sole(), offsetY: 14.0, width: 170.0, height: 36.0);

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        // Fixture draws "Signature:" with its baseline at user (72, 200) on a 612x792 page.
        // Native top = 792 - 200 - 0.8 * 12 = 582.4; width = 10 chars * 0.6 em * 12 pt = 72.
        $this->assertEqualsWithDelta(72.0, $resolved[0]->anchorRect->x, 1.0e-6);
        $this->assertEqualsWithDelta(582.4, $resolved[0]->anchorRect->y, 1.0e-6);
        $this->assertEqualsWithDelta(72.0, $resolved[0]->anchorRect->width, 1.0e-6);
        $this->assertEqualsWithDelta(596.4, $resolved[0]->resolvedRect->y, 1.0e-6);
    }

    public function test_a_rotated_page_reports_the_run_direction_in_displayed_space(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('rotated-pages'), 1);

        $this->assertNotEmpty($runs);
        foreach ($runs as $run) {
            $this->assertSame(
                TextDirection::TopToBottom,
                $run->direction,
                'On a /Rotate 90 page, text drawn horizontally reads top to bottom once displayed.',
            );
        }
    }

    public function test_an_anchor_on_a_rotated_page_resolves_inside_the_displayed_page(): void
    {
        $bytes = PdfFixtures::bytes('rotated-pages');
        $runs = (new TcPdfTextLocator)->extract($bytes, 1);
        $anchor = new Anchor('Signature:', AnchorOccurrence::sole(), page: 1, offsetX: 16.0, width: 170.0, height: 36.0);

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        // Page 1 is Letter with /Rotate 90, so the displayed page is 792 x 612.
        // "Signature:" has its baseline at user (72, 200); native x = 200 - 0.8 * 12 = ...
        // measured through the transform: the run occupies x 197.6..209.6, y 72..144.
        $this->assertEqualsWithDelta(197.6, $resolved[0]->anchorRect->x, 1.0e-6);
        $this->assertEqualsWithDelta(72.0, $resolved[0]->anchorRect->y, 1.0e-6);
        $this->assertEqualsWithDelta(12.0, $resolved[0]->anchorRect->width, 1.0e-6);
        $this->assertEqualsWithDelta(72.0, $resolved[0]->anchorRect->height, 1.0e-6);
        $this->assertEqualsWithDelta(213.6, $resolved[0]->resolvedRect->x, 1.0e-6);
    }

    public function test_an_anchor_on_an_offset_crop_box_page_is_measured_from_the_displayed_corner(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('cropbox-offset'), 1);
        $anchor = new Anchor('Signature:', AnchorOccurrence::sole(), page: 1, width: 100.0, height: 20.0);

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        // CropBox [36 48 576 744]; baseline at user (100, 200).
        // native x = 100 - 36 = 64; native y = (48 + 696) - (200 + 9.6) = 534.4
        $this->assertEqualsWithDelta(64.0, $resolved[0]->anchorRect->x, 1.0e-6);
        $this->assertEqualsWithDelta(534.4, $resolved[0]->anchorRect->y, 1.0e-6);
    }

    public function test_multiple_occurrences_are_ordered_top_to_bottom(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-occurrence'));
        $resolver = new AnchorResolver;

        $all = $resolver->resolve($runs, new Anchor('Signature:', AnchorOccurrence::all(), width: 1.0, height: 1.0));

        $ys = array_map(static fn ($r): float => round($r->anchorRect->y, 4), $all);
        $sorted = $ys;
        sort($sorted);

        $this->assertSame($sorted, $ys, 'Occurrences must be numbered in displayed reading order.');
        // Three standalone "Signature:" runs. The page also contains "Countersignature:",
        // which does not match: the anchor is case-sensitive and "s" is not "S".
        $this->assertCount(3, $all);
        $this->assertCount(
            1,
            $resolver->resolve($runs, new Anchor('signature:', AnchorOccurrence::all(), width: 1.0, height: 1.0)),
            'The lowercase anchor matches only the tail of "Countersignature:".',
        );
    }

    public function test_a_required_anchor_that_is_absent_fails_loudly(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('single-page-letter'));

        $this->expectException(AnchorNotFoundException::class);

        (new AnchorResolver)->resolve($runs, new Anchor('Witness Signature:', AnchorOccurrence::sole()));
    }

    public function test_an_ambiguous_required_anchor_fails_loudly(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('multi-occurrence'));

        $this->expectException(AmbiguousAnchorException::class);

        (new AnchorResolver)->resolve($runs, new Anchor('Signature:', AnchorOccurrence::sole()));
    }

    public function test_unicode_text_from_an_embedded_font_is_decoded_through_to_unicode(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('unicode-embedded-font'));

        $texts = array_map(static fn ($r): string => $r->text, $runs);
        $this->assertContains("\u{00DC}\u{00EF}\u{0107}\u{03A9}\u{20AC}\u{2713}", $texts);
    }

    public function test_a_scanned_page_yields_only_its_real_text_layer(): void
    {
        $runs = (new TcPdfTextLocator)->extract(PdfFixtures::bytes('scanned-image-page'));

        $this->assertCount(1, $runs, 'A raster page contributes no text; only the drawn run is reported.');
        $this->assertSame('Signature:', $runs[0]->text);
    }

    public function test_anchors_resolve_identically_before_and_after_assembly(): void
    {
        $locator = new TcPdfTextLocator;
        $resolver = new AnchorResolver;
        $anchor = new Anchor('Signature:', AnchorOccurrence::all(), offsetY: 16.0, width: 170.0, height: 36.0);

        foreach (['single-page-letter', 'rotated-pages', 'cropbox-offset', 'multi-occurrence', 'unicode-embedded-font'] as $fixture) {
            $source = PdfFixtures::bytes($fixture);
            $assembled = (new TcPdfAssembler)->assemble($source)->bytes;

            $before = $resolver->resolve($locator->extract($source), $anchor);
            $after = $resolver->resolve($locator->extract($assembled), $anchor);

            $this->assertSame(
                array_map(self::round(...), $before),
                array_map(self::round(...), $after),
                $fixture.': anchors moved across assembly.',
            );
        }
    }

    public function test_extraction_refuses_an_encrypted_document(): void
    {
        $this->expectException(TextExtractionException::class);

        (new TcPdfTextLocator)->extract(PdfFixtures::bytes('encrypted-aes128'));
    }

    /** @return array<string, mixed> */
    private static function round(ResolvedAnchor $resolved): array
    {
        $out = $resolved->toArray();
        $out['anchor_rect'] = array_map(static fn (float $v): float => round($v, 3), $out['anchor_rect']);
        $out['resolved_rect'] = array_map(static fn (float $v): float => round($v, 3), $out['resolved_rect']);

        return $out;
    }
}
