<?php

declare(strict_types=1);

/**
 * Stage 0 PDF fixture generator.
 *
 * Regenerate with:  php tests/Fixtures/pdf/generate.php
 *
 * Every fixture is synthetic and, with one exception, byte-reproducible: no timestamps,
 * no random file identifiers, no third-party font or image binaries. The exception is
 * encrypted-aes128.pdf, whose AES-128 crypt filter draws a fresh initialisation vector
 * for every encrypted string and stream, so its ciphertext differs on each run. Its
 * /Encrypt dictionary, which is the only part preflight looks at, is stable.
 *
 * Alongside the PDFs the script writes manifest.json, which records for each fixture
 * the expected preflight verdict, the page geometry, a probe rectangle burned into the
 * source content stream, the native rectangle a test overlay must land on, and the text
 * runs with their expected native rectangles.
 */

use Tests\Fixtures\Pdf\FixtureGeometry;
use Tests\Fixtures\Pdf\PdfFixtureWriter;
use Tests\Fixtures\Pdf\SyntheticTrueTypeFont;

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/PdfFixtureWriter.php';
require __DIR__.'/SyntheticTrueTypeFont.php';
require __DIR__.'/FixtureGeometry.php';

const OUT_DIR = __DIR__;

const FONT_SIZE = 12.0;
/** Courier is metrically uniform: every glyph advances 600/1000 em. */
const COURIER_ADVANCE = 0.6;
/** Nominal text-run box used by the fixture manifest and by the text locator. */
const RUN_ASCENT = 0.8;
const RUN_DESCENT = 0.2;

/** @var array<int, array<string, mixed>> */
$manifest = [];

/**
 * @param  array{float, float, float, float}  $cropBox
 * @param  array<int, array{string, float, float}>  $texts  [text, user-space x, baseline user-space y]
 * @return array{content: string, probeUser: array{float,float,float,float}, runs: array<int, array<string, mixed>>}
 */
function buildTextPage(array $cropBox, int $rotation, array $texts, array $probeNative): array
{
    $content = "q\n";

    // A filled probe square burned into the source page at a known native coordinate.
    // Import fidelity is checked by locating this square in the assembled output.
    $probeUser = FixtureGeometry::nativeRectToUser(
        $probeNative[0],
        $probeNative[1],
        $probeNative[2],
        $probeNative[3],
        $cropBox,
        $rotation,
    );
    $content .= sprintf(
        "0 g\n%.4F %.4F %.4F %.4F re f\n",
        $probeUser[0],
        $probeUser[1],
        $probeUser[2] - $probeUser[0],
        $probeUser[3] - $probeUser[1],
    );

    $runs = [];
    foreach ($texts as [$text, $ux, $uy]) {
        $content .= sprintf("BT\n/F1 %.1F Tf\n%.4F %.4F Td\n(%s) Tj\nET\n", FONT_SIZE, $ux, $uy, pdfEscape($text));
        $runs[] = textRunExpectation($text, $ux, $uy, $cropBox, $rotation);
    }

    return ['content' => $content."Q\n", 'probeUser' => $probeUser, 'runs' => $runs];
}

/**
 * @param  array{float, float, float, float}  $cropBox
 * @return array<string, mixed>
 */
function textRunExpectation(string $text, float $ux, float $uy, array $cropBox, int $rotation): array
{
    $width = strlen($text) * COURIER_ADVANCE * FONT_SIZE;
    $native = FixtureGeometry::userRectToNative(
        $ux,
        $uy - RUN_DESCENT * FONT_SIZE,
        $ux + $width,
        $uy + RUN_ASCENT * FONT_SIZE,
        $cropBox,
        $rotation,
    );

    return [
        'text' => $text,
        'native_rect' => array_map(static fn (float $v): float => round($v, 4), $native),
    ];
}

function pdfEscape(string $text): string
{
    return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
}

function courierFontDict(): string
{
    return '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding'
        .' /FirstChar 32 /LastChar 126 /Widths ['.trim(str_repeat('600 ', 95)).'] >>';
}

/**
 * @param  array<int, array<string, mixed>>  $pages  Each: box, rotate, content, resources, userUnit
 */
function assemblePdf(PdfFixtureWriter $writer, array $pages, string $catalogExtra = ''): string
{
    $pagesObj = $writer->reserve();
    $kids = [];
    foreach ($pages as $page) {
        $contentObj = $writer->addStream('<< >>', (string) $page['content']);
        $entries = sprintf(
            '/Type /Page /Parent %d 0 R /MediaBox [%s] /CropBox [%s] /Rotate %d /Resources %s /Contents %d 0 R',
            $pagesObj,
            implode(' ', array_map(static fn ($v): string => sprintf('%.4F', $v), $page['mediaBox'])),
            implode(' ', array_map(static fn ($v): string => sprintf('%.4F', $v), $page['cropBox'])),
            (int) $page['rotate'],
            (string) $page['resources'],
            $contentObj,
        );
        if (isset($page['userUnit']) && (float) $page['userUnit'] !== 1.0) {
            $entries .= sprintf(' /UserUnit %.4F', $page['userUnit']);
        }
        if (isset($page['annots'])) {
            $entries .= ' /Annots '.$page['annots'];
        }
        $kids[] = $writer->add('<< '.$entries.' >>');
    }

    $writer->put($pagesObj, sprintf(
        '<< /Type /Pages /Kids [%s] /Count %d >>',
        implode(' ', array_map(static fn (int $k): string => $k.' 0 R', $kids)),
        count($kids),
    ));

    $root = $writer->add('<< /Type /Catalog /Pages '.$pagesObj.' 0 R'.$catalogExtra.' >>');

    return (string) $root;
}

/**
 * @param  array<string, mixed>  $entry
 */
function emit(string $name, string $bytes, array $entry): void
{
    global $manifest;
    file_put_contents(OUT_DIR.'/'.$name.'.pdf', $bytes);
    $entry['name'] = $name;
    $entry['file'] = $name.'.pdf';
    $entry['bytes'] = strlen($bytes);
    $manifest[] = $entry;
    printf("  %-28s %7d bytes\n", $name.'.pdf', strlen($bytes));
}

/**
 * Standard single-format fixture: one or more Courier text pages.
 *
 * @param  array<int, array<string, mixed>>  $specs  Each: size [w,h], crop [x0,y0,x1,y1], rotate, texts, probe, overlay
 * @return array<string, mixed>
 */
function textFixture(array $specs, string $mode = 'xreftable', string $catalogExtra = ''): array
{
    $writer = new PdfFixtureWriter($mode);
    $fontObj = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$fontObj.' 0 R >> >>';

    $pages = [];
    $manifestPages = [];
    foreach ($specs as $index => $spec) {
        /** @var array{float,float,float,float} $crop */
        $crop = $spec['crop'];
        $rotation = (int) $spec['rotate'];
        $built = buildTextPage($crop, $rotation, $spec['texts'], $spec['probe']);
        $pages[] = [
            'mediaBox' => $spec['media'],
            'cropBox' => $crop,
            'rotate' => $rotation,
            'resources' => $resources,
            'content' => $built['content'],
            'userUnit' => $spec['userUnit'] ?? 1.0,
        ];
        [$nativeWidth, $nativeHeight] = FixtureGeometry::displayedSize($crop[2] - $crop[0], $crop[3] - $crop[1], $rotation);
        $manifestPages[] = [
            'page' => $index + 1,
            'media_box' => $spec['media'],
            'crop_box' => $crop,
            'rotation' => $rotation,
            'user_unit' => (float) ($spec['userUnit'] ?? 1.0),
            'native_width' => round($nativeWidth, 4),
            'native_height' => round($nativeHeight, 4),
            'probe_native' => $spec['probe'],
            'probe_user' => array_map(static fn (float $v): float => round($v, 4), $built['probeUser']),
            'overlay_native' => $spec['overlay'],
            'text_runs' => $built['runs'],
        ];
    }

    $root = assemblePdf($writer, $pages, $catalogExtra);

    return ['bytes' => $writer->build((int) $root), 'pages' => $manifestPages];
}

echo "Generating Stage 0 PDF fixtures...\n";

// ---------------------------------------------------------------------------
// 1. Single page, US Letter, no rotation, CropBox == MediaBox at the origin.
// ---------------------------------------------------------------------------
$letter = [0.0, 0.0, 612.0, 792.0];
$built = textFixture([[
    'media' => $letter,
    'crop' => $letter,
    'rotate' => 0,
    'texts' => [
        ['Mutual Non-Disclosure Agreement', 72.0, 700.0],
        ['This synthetic document exists only for import fidelity tests.', 72.0, 660.0],
        ['Signature:', 72.0, 200.0],
        ['Printed Name:', 320.0, 200.0],
    ],
    'probe' => [72.0, 72.0, 24.0, 12.0],
    'overlay' => [100.0, 300.0, 170.0, 36.0],
]]);
emit('single-page-letter', $built['bytes'], [
    'description' => 'One US Letter page, no rotation, CropBox equal to MediaBox at the origin.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 2. Multi page with mixed page sizes.
// ---------------------------------------------------------------------------
$a4 = [0.0, 0.0, 595.0, 842.0];
$small = [0.0, 0.0, 400.0, 600.0];
$built = textFixture([
    [
        'media' => $letter, 'crop' => $letter, 'rotate' => 0,
        'texts' => [['Page one of three (Letter).', 72.0, 700.0], ['Signature:', 72.0, 120.0]],
        'probe' => [50.0, 50.0, 20.0, 10.0],
        'overlay' => [90.0, 400.0, 120.0, 30.0],
    ],
    [
        'media' => $a4, 'crop' => $a4, 'rotate' => 0,
        'texts' => [['Page two of three (A4).', 60.0, 760.0], ['Initials:', 60.0, 110.0]],
        'probe' => [40.0, 40.0, 20.0, 10.0],
        'overlay' => [80.0, 380.0, 120.0, 30.0],
    ],
    [
        'media' => $small, 'crop' => $small, 'rotate' => 0,
        'texts' => [['Page three of three (400x600).', 40.0, 540.0], ['Date:', 40.0, 90.0]],
        'probe' => [30.0, 30.0, 20.0, 10.0],
        'overlay' => [60.0, 260.0, 100.0, 24.0],
    ],
]);
emit('multi-page-mixed-size', $built['bytes'], [
    'description' => 'Three pages of different sizes (Letter, A4, 400x600).',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 3. Rotated pages: /Rotate 90, 180 and 270.
// ---------------------------------------------------------------------------
$rotatedSpecs = [];
foreach ([90, 180, 270] as $rotation) {
    $rotatedSpecs[] = [
        'media' => $letter, 'crop' => $letter, 'rotate' => $rotation,
        'texts' => [
            ['Rotated page '.$rotation.' degrees.', 72.0, 700.0],
            ['Signature:', 72.0, 200.0],
        ],
        'probe' => [36.0, 36.0, 24.0, 12.0],
        'overlay' => [120.0, 240.0, 150.0, 32.0],
    ];
}
$built = textFixture($rotatedSpecs);
emit('rotated-pages', $built['bytes'], [
    'description' => 'Three US Letter pages with /Rotate 90, 180 and 270.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 4. Nonzero CropBox offset, with and without rotation.
// ---------------------------------------------------------------------------
$offsetCrop = [36.0, 48.0, 576.0, 744.0];
$built = textFixture([
    [
        'media' => $letter, 'crop' => $offsetCrop, 'rotate' => 0,
        'texts' => [['Offset CropBox, no rotation.', 100.0, 600.0], ['Signature:', 100.0, 200.0]],
        'probe' => [40.0, 40.0, 24.0, 12.0],
        'overlay' => [80.0, 300.0, 140.0, 30.0],
    ],
    [
        'media' => $letter, 'crop' => $offsetCrop, 'rotate' => 90,
        'texts' => [['Offset CropBox, rotated 90.', 100.0, 600.0], ['Signature:', 100.0, 200.0]],
        'probe' => [40.0, 40.0, 24.0, 12.0],
        'overlay' => [200.0, 120.0, 140.0, 30.0],
    ],
]);
emit('cropbox-offset', $built['bytes'], [
    'description' => 'MediaBox 612x792 with CropBox [36 48 576 744]; page 2 additionally has /Rotate 90.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 5. UserUnit page.
// ---------------------------------------------------------------------------
$built = textFixture([[
    'media' => $letter, 'crop' => $letter, 'rotate' => 0, 'userUnit' => 2.0,
    'texts' => [['UserUnit 2.0 page.', 72.0, 700.0], ['Signature:', 72.0, 200.0]],
    'probe' => [72.0, 72.0, 24.0, 12.0],
    'overlay' => [100.0, 300.0, 170.0, 36.0],
]]);
emit('user-unit', $built['bytes'], [
    'description' => 'US Letter page carrying /UserUnit 2.0: each default user-space unit is two points.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 6. Multiple occurrences of the same anchor string on one page.
// ---------------------------------------------------------------------------
$built = textFixture([[
    'media' => $letter, 'crop' => $letter, 'rotate' => 0,
    'texts' => [
        ['Signature:', 72.0, 600.0],
        ['Signature:', 72.0, 500.0],
        ['Signature:', 72.0, 400.0],
        ['Countersignature:', 72.0, 300.0],
    ],
    'probe' => [72.0, 72.0, 24.0, 12.0],
    'overlay' => [300.0, 300.0, 150.0, 30.0],
]]);
emit('multi-occurrence', $built['bytes'], [
    'description' => 'One page where the anchor string "Signature:" occurs three times.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 7. Cross-reference stream.
// ---------------------------------------------------------------------------
$built = textFixture([[
    'media' => $letter, 'crop' => $letter, 'rotate' => 0,
    'texts' => [['Cross-reference stream document.', 72.0, 700.0], ['Signature:', 72.0, 200.0]],
    'probe' => [72.0, 72.0, 24.0, 12.0],
    'overlay' => [100.0, 300.0, 170.0, 36.0],
]], 'xrefstream');
emit('xref-stream', $built['bytes'], [
    'description' => 'PDF 1.5 document using a cross-reference stream instead of a classic xref table.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 8. Object streams inside a cross-reference stream document.
// ---------------------------------------------------------------------------
$built = textFixture([[
    'media' => $letter, 'crop' => $letter, 'rotate' => 0,
    'texts' => [['Object stream document.', 72.0, 700.0], ['Signature:', 72.0, 200.0]],
    'probe' => [72.0, 72.0, 24.0, 12.0],
    'overlay' => [100.0, 300.0, 170.0, 36.0],
]], 'objstream');
emit('object-stream', $built['bytes'], [
    'description' => 'PDF 1.5 document whose catalog, page tree and page dictionaries live in an /ObjStm.',
    'expected_preflight' => 'accept',
    'expected_rejections' => [],
    'pages' => $built['pages'],
]);

// ---------------------------------------------------------------------------
// 9. Unicode text drawn with an embedded synthetic TrueType font (Identity-H).
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $string = "\u{00DC}\u{00EF}\u{0107}\u{03A9}\u{20AC}\u{2713}";
    $codePoints = array_map(
        static fn (string $char): int => (int) hexdec(bin2hex(iconv('UTF-8', 'UCS-2BE', $char) ?: '')),
        preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [],
    );
    $font = new SyntheticTrueTypeFont($codePoints);
    $program = $font->build();
    $codeToGlyph = $font->codeToGlyph();

    $writer = new PdfFixtureWriter;
    $fontFile = $writer->addStream('<< /Length1 '.strlen($program).' >>', $program);

    $toUnicode = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
        ."/CMapName /Identity-H def\n/CMapType 2 def\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
        ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
        .count($codeToGlyph)." beginbfchar\n";
    foreach ($codeToGlyph as $codePoint => $glyphId) {
        $toUnicode .= sprintf("<%04X> <%04X>\n", $glyphId, $codePoint);
    }
    $toUnicode .= "endbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    $toUnicodeObj = $writer->addStream('<< >>', $toUnicode);

    $descriptor = $writer->add(sprintf(
        '<< /Type /FontDescriptor /FontName /ESGNFX+ESignFixtureFont /Flags 4 /FontBBox [50 %d 550 %d]'
        .' /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight 700 /StemV 80 /FontFile2 %d 0 R >>',
        SyntheticTrueTypeFont::DESCENT,
        SyntheticTrueTypeFont::ASCENT,
        SyntheticTrueTypeFont::ASCENT,
        SyntheticTrueTypeFont::DESCENT,
        $fontFile,
    ));

    $cidFont = $writer->add(sprintf(
        '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /ESGNFX+ESignFixtureFont'
        .' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
        .' /FontDescriptor %d 0 R /DW %d /CIDToGIDMap /Identity >>',
        $descriptor,
        SyntheticTrueTypeFont::ADVANCE_WIDTH,
    ));

    $type0 = $writer->add(sprintf(
        '<< /Type /Font /Subtype /Type0 /BaseFont /ESGNFX+ESignFixtureFont /Encoding /Identity-H'
        .' /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
        $cidFont,
        $toUnicodeObj,
    ));

    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R /F2 '.$type0.' 0 R >> >>';

    $glyphHex = '';
    foreach ($codeToGlyph as $glyphId) {
        $glyphHex .= sprintf('%04X', $glyphId);
    }

    $probeNative = [72.0, 72.0, 24.0, 12.0];
    $probeUser = FixtureGeometry::nativeRectToUser($probeNative[0], $probeNative[1], $probeNative[2], $probeNative[3], $letter, 0);
    $content = sprintf("q\n0 g\n%.4F %.4F %.4F %.4F re f\n", $probeUser[0], $probeUser[1], $probeUser[2] - $probeUser[0], $probeUser[3] - $probeUser[1])
        ."BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(Embedded Unicode font sample:) Tj\nET\n"
        ."BT\n/F2 12.0 Tf\n72.0000 660.0000 Td\n<".$glyphHex."> Tj\nET\n"
        ."BT\n/F1 12.0 Tf\n72.0000 200.0000 Td\n(Signature:) Tj\nET\n"
        ."Q\n";

    $unicodeWidth = count($codeToGlyph) * (SyntheticTrueTypeFont::ADVANCE_WIDTH / 1000) * FONT_SIZE;

    $pages = [[
        'mediaBox' => $letter, 'cropBox' => $letter, 'rotate' => 0,
        'resources' => $resources, 'content' => $content,
    ]];
    $root = assemblePdf($writer, $pages);

    emit('unicode-embedded-font', $writer->build((int) $root), [
        'description' => 'Identity-H Type0 text drawn with an embedded synthetic TrueType subset plus a /ToUnicode CMap.',
        'expected_preflight' => 'accept',
        'expected_rejections' => [],
        'pages' => [[
            'page' => 1,
            'media_box' => $letter,
            'crop_box' => $letter,
            'rotation' => 0,
            'user_unit' => 1.0,
            'native_width' => 612.0,
            'native_height' => 792.0,
            'probe_native' => $probeNative,
            'probe_user' => array_map(static fn (float $v): float => round($v, 4), $probeUser),
            'overlay_native' => [100.0, 300.0, 170.0, 36.0],
            'text_runs' => [
                textRunExpectation('Embedded Unicode font sample:', 72.0, 700.0, $letter, 0),
                [
                    'text' => $string,
                    'native_rect' => array_map(static fn (float $v): float => round($v, 4), FixtureGeometry::userRectToNative(
                        72.0,
                        660.0 - RUN_DESCENT * FONT_SIZE,
                        72.0 + $unicodeWidth,
                        660.0 + RUN_ASCENT * FONT_SIZE,
                        $letter,
                        0,
                    )),
                ],
                textRunExpectation('Signature:', 72.0, 200.0, $letter, 0),
            ],
        ]],
    ]);
})();

// ---------------------------------------------------------------------------
// 10. Scanned-page stand-in: a full-page raster image.
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $writer = new PdfFixtureWriter;

    // 64x64 8-bit greyscale checkerboard, Flate compressed.
    $raw = '';
    for ($y = 0; $y < 64; $y++) {
        for ($x = 0; $x < 64; $x++) {
            $raw .= chr((($x >> 3) + ($y >> 3)) % 2 === 0 ? 0xF0 : 0x30);
        }
    }
    $image = $writer->addStream(
        '<< /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceGray'
        .' /BitsPerComponent 8 /Filter /FlateDecode >>',
        (string) gzcompress($raw, 9),
    );

    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> /XObject << /Im1 '.$image.' 0 R >> >>';

    $probeNative = [72.0, 72.0, 24.0, 12.0];
    $probeUser = FixtureGeometry::nativeRectToUser($probeNative[0], $probeNative[1], $probeNative[2], $probeNative[3], $letter, 0);
    $content = "q\n612 0 0 792 0 0 cm\n/Im1 Do\nQ\n"
        .sprintf("q\n0 g\n%.4F %.4F %.4F %.4F re f\nQ\n", $probeUser[0], $probeUser[1], $probeUser[2] - $probeUser[0], $probeUser[3] - $probeUser[1])
        ."BT\n/F1 12.0 Tf\n72.0000 200.0000 Td\n(Signature:) Tj\nET\n";

    $root = assemblePdf($writer, [[
        'mediaBox' => $letter, 'cropBox' => $letter, 'rotate' => 0,
        'resources' => $resources, 'content' => $content,
    ]]);

    emit('scanned-image-page', $writer->build((int) $root), [
        'description' => 'Full-page 64x64 greyscale image standing in for a scanned page, plus one real text run.',
        'expected_preflight' => 'accept',
        'expected_rejections' => [],
        'pages' => [[
            'page' => 1,
            'media_box' => $letter,
            'crop_box' => $letter,
            'rotation' => 0,
            'user_unit' => 1.0,
            'native_width' => 612.0,
            'native_height' => 792.0,
            'probe_native' => $probeNative,
            'probe_user' => array_map(static fn (float $v): float => round($v, 4), $probeUser),
            'overlay_native' => [100.0, 300.0, 170.0, 36.0],
            'text_runs' => [textRunExpectation('Signature:', 72.0, 200.0, $letter, 0)],
        ]],
    ]);
})();

// ---------------------------------------------------------------------------
// 11. AcroForm text field plus link and square annotations.
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $writer = new PdfFixtureWriter;
    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> >>';

    $pagesObj = $writer->reserve();
    $fieldObj = $writer->reserve();
    $pageObj = $writer->reserve();

    $writer->put($fieldObj, sprintf(
        '<< /Type /Annot /Subtype /Widget /FT /Tx /T (full_name) /Rect [72 300 300 324] /F 4 /P %d 0 R'
        .' /DA (/Helv 0 Tf 0 g) /V () >>',
        $pageObj,
    ));
    $link = $writer->add('<< /Type /Annot /Subtype /Link /Rect [72 250 200 270] /Border [0 0 0]'
        .' /A << /S /URI /URI (https://example.test/synthetic) >> >>');
    $square = $writer->add('<< /Type /Annot /Subtype /Square /Rect [400 250 500 300] /C [0 0 1] /F 4 >>');

    $probeNative = [72.0, 72.0, 24.0, 12.0];
    $probeUser = FixtureGeometry::nativeRectToUser($probeNative[0], $probeNative[1], $probeNative[2], $probeNative[3], $letter, 0);
    $content = sprintf("q\n0 g\n%.4F %.4F %.4F %.4F re f\nQ\n", $probeUser[0], $probeUser[1], $probeUser[2] - $probeUser[0], $probeUser[3] - $probeUser[1])
        ."BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(Form fields and annotations.) Tj\nET\n"
        ."BT\n/F1 12.0 Tf\n72.0000 200.0000 Td\n(Signature:) Tj\nET\n";

    $contentObj = $writer->addStream('<< >>', $content);
    $writer->put($pageObj, sprintf(
        '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Rotate 0'
        .' /Resources %s /Contents %d 0 R /Annots [%d 0 R %d 0 R %d 0 R] >>',
        $pagesObj,
        $resources,
        $contentObj,
        $fieldObj,
        $link,
        $square,
    ));
    $writer->put($pagesObj, '<< /Type /Pages /Kids ['.$pageObj.' 0 R] /Count 1 >>');
    $root = $writer->add(sprintf(
        '<< /Type /Catalog /Pages %d 0 R /AcroForm << /Fields [%d 0 R] /DA (/Helv 0 Tf 0 g) >> >>',
        $pagesObj,
        $fieldObj,
    ));

    emit('form-fields-annotations', $writer->build($root), [
        'description' => 'AcroForm with one text widget, a URI link annotation and a square annotation.',
        'expected_preflight' => 'accept',
        'expected_rejections' => [],
        'notes' => 'Accepted with warnings: widget and link annotations are not carried into the assembled output.',
        'pages' => [[
            'page' => 1,
            'media_box' => $letter,
            'crop_box' => $letter,
            'rotation' => 0,
            'user_unit' => 1.0,
            'native_width' => 612.0,
            'native_height' => 792.0,
            'probe_native' => $probeNative,
            'probe_user' => array_map(static fn (float $v): float => round($v, 4), $probeUser),
            'overlay_native' => [100.0, 400.0, 170.0, 36.0],
            'text_runs' => [
                textRunExpectation('Form fields and annotations.', 72.0, 700.0, $letter, 0),
                textRunExpectation('Signature:', 72.0, 200.0, $letter, 0),
            ],
        ]],
    ]);
})();

// ---------------------------------------------------------------------------
// 12. Encrypted document (standard security handler, RC4-40, empty user password).
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $writer = new PdfFixtureWriter;
    $writer->enableEncryption();
    $writer->writeEncryptDictionary();

    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> >>';
    $content = "BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(Encrypted synthetic document.) Tj\nET\n";
    $root = assemblePdf($writer, [[
        'mediaBox' => $letter, 'cropBox' => $letter, 'rotate' => 0,
        'resources' => $resources, 'content' => $content,
    ]]);

    emit('encrypted-aes128', $writer->build((int) $root), [
        'description' => 'Standard security handler (V4/R4, AES-128) with an empty user password.',
        'expected_preflight' => 'reject',
        'expected_rejections' => ['encrypted'],
        'pages' => [],
    ]);
})();

// ---------------------------------------------------------------------------
// 13. Already-signed document (structural signature dictionary).
// ---------------------------------------------------------------------------
(static function (): void {
    $writer = new PdfFixtureWriter;
    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> >>';

    $pagesObj = $writer->reserve();
    $sigValue = $writer->add(
        '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached'
        .' /ByteRange [0 840 3400 1200] /Contents <'.str_repeat('00', 16).'>'
        .' /M (D:20260101000000Z) /Reason (Synthetic structural signature) >>',
    );
    $sigField = $writer->add(sprintf(
        '<< /Type /Annot /Subtype /Widget /FT /Sig /T (signature_one) /Rect [72 150 300 200] /F 4 /V %d 0 R >>',
        $sigValue,
    ));

    $content = "BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(Already signed synthetic document.) Tj\nET\n";
    $contentObj = $writer->addStream('<< >>', $content);
    $pageObj = $writer->add(sprintf(
        '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Rotate 0'
        .' /Resources %s /Contents %d 0 R /Annots [%d 0 R] >>',
        $pagesObj,
        $resources,
        $contentObj,
        $sigField,
    ));
    $writer->put($pagesObj, '<< /Type /Pages /Kids ['.$pageObj.' 0 R] /Count 1 >>');
    $root = $writer->add(sprintf(
        '<< /Type /Catalog /Pages %d 0 R /AcroForm << /Fields [%d 0 R] /SigFlags 3 >> >>',
        $pagesObj,
        $sigField,
    ));

    emit('already-signed', $writer->build($root), [
        'description' => 'AcroForm /SigFlags 3 with a populated signature field. The /Contents blob is a placeholder,'
            .' so the document is structurally signed but not cryptographically valid.',
        'expected_preflight' => 'reject',
        'expected_rejections' => ['already_signed'],
        'pages' => [],
    ]);
})();

// ---------------------------------------------------------------------------
// 14. Document-level JavaScript and an /OpenAction JavaScript action.
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $writer = new PdfFixtureWriter;
    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> >>';

    $jsStream = $writer->addStream('<< >>', "app.alert('synthetic');\n");
    $jsAction = $writer->add('<< /Type /Action /S /JavaScript /JS '.$jsStream.' 0 R >>');
    $namesJs = $writer->add('<< /Names [(SyntheticScript) '.$jsAction.' 0 R] >>');
    $names = $writer->add('<< /JavaScript '.$namesJs.' 0 R >>');

    $content = "BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(Document with JavaScript.) Tj\nET\n";
    $root = assemblePdf($writer, [[
        'mediaBox' => $letter, 'cropBox' => $letter, 'rotate' => 0,
        'resources' => $resources, 'content' => $content,
    ]], ' /Names '.$names.' 0 R /OpenAction '.$jsAction.' 0 R');

    emit('javascript-action', $writer->build((int) $root), [
        'description' => 'Name-tree JavaScript plus an /OpenAction JavaScript action.',
        'expected_preflight' => 'reject',
        'expected_rejections' => ['javascript'],
        'pages' => [],
    ]);
})();

// ---------------------------------------------------------------------------
// 15. XFA form.
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $writer = new PdfFixtureWriter;
    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> >>';

    $xfa = $writer->addStream('<< >>', '<config xmlns="http://www.xfa.org/schema/xci/3.0/"></config>');
    $content = "BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(XFA document.) Tj\nET\n";
    $pagesRoot = assemblePdf($writer, [[
        'mediaBox' => $letter, 'cropBox' => $letter, 'rotate' => 0,
        'resources' => $resources, 'content' => $content,
    ]], ' /AcroForm << /Fields [] /XFA [(config) '.$xfa.' 0 R] >>');

    emit('xfa-form', $writer->build((int) $pagesRoot), [
        'description' => 'AcroForm carrying an /XFA array (XML Forms Architecture).',
        'expected_preflight' => 'reject',
        'expected_rejections' => ['xfa'],
        'pages' => [],
    ]);
})();

// ---------------------------------------------------------------------------
// 16. Embedded file attachment.
// ---------------------------------------------------------------------------
(static function () use ($letter): void {
    $writer = new PdfFixtureWriter;
    $courier = $writer->add(courierFontDict());
    $resources = '<< /Font << /F1 '.$courier.' 0 R >> >>';

    $embedded = $writer->addStream('<< /Type /EmbeddedFile /Subtype /text#2Fplain >>', "synthetic attachment\n");
    $filespec = $writer->add('<< /Type /Filespec /F (attachment.txt) /UF (attachment.txt) /EF << /F '.$embedded.' 0 R >> >>');
    $namesEf = $writer->add('<< /Names [(attachment.txt) '.$filespec.' 0 R] >>');
    $names = $writer->add('<< /EmbeddedFiles '.$namesEf.' 0 R >>');

    $content = "BT\n/F1 12.0 Tf\n72.0000 700.0000 Td\n(Document with an embedded file.) Tj\nET\n";
    $root = assemblePdf($writer, [[
        'mediaBox' => $letter, 'cropBox' => $letter, 'rotate' => 0,
        'resources' => $resources, 'content' => $content,
    ]], ' /Names '.$names.' 0 R');

    emit('embedded-file', $writer->build((int) $root), [
        'description' => 'Name-tree /EmbeddedFiles attachment.',
        'expected_preflight' => 'reject',
        'expected_rejections' => ['embedded_file'],
        'pages' => [],
    ]);
})();

file_put_contents(
    OUT_DIR.'/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);

printf("\n%d fixtures written to %s\n", count($manifest), OUT_DIR);
