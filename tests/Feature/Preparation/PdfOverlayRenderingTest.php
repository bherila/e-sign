<?php

declare(strict_types=1);

namespace Tests\Feature\Preparation;

use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\OverlayImage;
use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\OverlayText;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\TcPdf\CoreFontMetrics;
use Tests\Support\PdfFixtures;
use Tests\TestCase;

/**
 * The marks the assembler can draw, and the documents it can append.
 *
 * Text and images are the two overlays a finalization actually needs, and both are new
 * capability on top of the Stage 0 probe rectangle. What is pinned here is the part that would
 * otherwise be discovered in production: that the text lands in the document with the standard
 * font declaration a viewer already has, that an image is fitted rather than stretched, and
 * that appended pages are numbered after the source's so an overlay can address them.
 */
class PdfOverlayRenderingTest extends TestCase
{
    /** A 1x1 transparent PNG. Small enough to be obviously synthetic. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_text_is_drawn_with_the_standard_font_declaration(): void
    {
        $assembled = $this->assembler()->assemble(
            PdfFixtures::bytes('single-page-letter'),
            [new OverlayText(1, new NativeRect(72.0, 300.0, 300.0, 18.0), 'Synthetic Signer', 10.0)],
        );

        // The page carries only /BaseFont /Courier: the standard-14 declaration every
        // conforming viewer already has. No font program is embedded.
        $this->assertStringContainsString('/BaseFont /Courier', $assembled->bytes);
        $this->assertStringContainsString('/WinAnsiEncoding', $assembled->bytes);
        $this->assertStringNotContainsString('/FontFile2', $assembled->bytes);
        $this->assertStringContainsString('Synthetic Signer', $this->contentStreams($assembled->bytes));
    }

    public function test_an_image_overlay_is_embedded_and_fitted_inside_its_rectangle(): void
    {
        $assembled = $this->assembler()->assemble(
            PdfFixtures::bytes('single-page-letter'),
            [new OverlayImage(1, new NativeRect(72.0, 600.0, 170.0, 36.0), (string) base64_decode(self::PNG, true))],
        );

        $this->assertStringContainsString('/Subtype /Image', $assembled->bytes);

        // A 1x1 source in a 170x36 box fits to 36x36 and is centred, never stretched to the
        // box's proportions: a stretched signature is not the mark the person drew.
        $this->assertMatchesRegularExpression('/36\.000000 0 0 36\.000000 /', $this->contentStreams($assembled->bytes));
    }

    public function test_an_appended_document_becomes_the_last_pages_and_can_carry_overlays(): void
    {
        $source = PdfFixtures::bytes('single-page-letter');
        $appended = PdfFixtures::bytes('multi-page-mixed-size');

        $assembled = $this->assembler()->assemble(
            $source,
            [new OverlayText(4, new NativeRect(40.0, 100.0, 300.0, 14.0), 'Appended page marker', 9.0)],
            [$appended],
        );

        // Source geometry still describes the source alone; the output has all four pages.
        $this->assertCount(1, $assembled->sourcePages);
        $this->assertCount(4, $assembled->outputPages);

        // The overlay addressed output page 4, which is the appended document's last page.
        $this->assertStringContainsString('Appended page marker', $this->contentStreams($assembled->bytes));
    }

    public function test_an_unreadable_image_is_refused_rather_than_dropped(): void
    {
        $this->expectException(AssemblyException::class);
        $this->expectExceptionMessage('not a readable raster image');

        $this->assembler()->assemble(
            PdfFixtures::bytes('single-page-letter'),
            [new OverlayImage(1, new NativeRect(72.0, 600.0, 170.0, 36.0), 'this is not an image')],
        );
    }

    public function test_a_probe_rectangle_still_draws_as_it_did(): void
    {
        $assembled = $this->assembler()->assemble(
            PdfFixtures::bytes('single-page-letter'),
            [new OverlayRectangle(1, new NativeRect(100.0, 300.0, 170.0, 36.0))],
        );

        $this->assertStringContainsString('%PDF-', $assembled->bytes);
        $this->assertCount(1, $assembled->outputPages);
    }

    // ---------------------------------------------------------------------------------
    // Metrics
    // ---------------------------------------------------------------------------------

    /**
     * Courier is fixed pitch, so the width of a line is arithmetic rather than a lookup.
     *
     * That is the whole reason the bundled metrics file is defensible: it states one property
     * of the face, not a transcribed table of per-glyph advances.
     */
    public function test_the_bundled_face_measures_exactly(): void
    {
        $this->assertSame(0.6, CoreFontMetrics::ADVANCE_RATIO);
        $this->assertSame(60.0, CoreFontMetrics::width('0123456789', 10.0));

        // A value that does not fit shrinks; it is never truncated.
        $size = CoreFontMetrics::fittedSize('0123456789', 30.0, 10.0);
        $this->assertLessThan(10.0, $size);
        $this->assertLessThanOrEqual(30.0 + 0.001, CoreFontMetrics::width('0123456789', $size));
    }

    public function test_wrapping_breaks_on_spaces_and_hard_splits_an_overlong_word(): void
    {
        // 10 characters per line at size 10 in a 60pt column.
        $this->assertSame(
            ['alpha beta', 'gamma'],
            CoreFontMetrics::wrap('alpha beta gamma', 60.0, 10.0),
        );

        $this->assertSame(
            ['aaaaaaaaaa', 'aaaa'],
            CoreFontMetrics::wrap(str_repeat('a', 14), 60.0, 10.0),
        );
    }

    private function assembler(): PdfAssembler
    {
        return app(PdfAssembler::class);
    }

    /** Every content stream, inflated, concatenated. */
    private function contentStreams(string $pdf): string
    {
        $text = '';
        $matches = [];

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= "\n".(is_string($inflated) ? $inflated : $stream);
        }

        return $text;
    }
}
