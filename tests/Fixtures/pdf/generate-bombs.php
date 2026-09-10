<?php

declare(strict_types=1);

/**
 * Resource-exhaustion fixture generator (issue #88, security review U-1 and U-2).
 *
 * Regenerate with:  php tests/Fixtures/pdf/generate-bombs.php
 *
 * Every fixture is synthetic, byte-reproducible, and **small on disk** — that is the whole
 * point of the corpus. A decompression bomb that was large on disk would be stopped by
 * `max_bytes` and would prove nothing; these are the files that pass every cheap gate and
 * only cost something once a parser starts work on them.
 *
 * The fixtures are sized against the *test* profile in
 * tests/Feature/Preparation/PdfPreflightLimitsTest.php, not against the shipped defaults:
 * a fixture built to trip a 256 MiB aggregate ceiling would have to inflate to 256 MiB in
 * CI to prove anything, which is a slow test that measures zlib rather than this
 * application. The mechanism is proved at a small scale here; that the shipped numbers are
 * the numbers we claim is asserted separately against `config/esign.php`.
 *
 * Alongside the PDFs this writes bombs/manifest.json, recording for each fixture what it
 * is, what preflight must answer, and how much data it would produce if nothing stopped
 * it.
 */

use Tests\Fixtures\Pdf\PdfFixtureWriter;

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/PdfFixtureWriter.php';

const BOMB_OUT_DIR = __DIR__.'/bombs';

const MIB = 1_048_576;

/** @var array<int, array<string, mixed>> */
$manifest = [];

/**
 * @param  array<string, mixed>  $entry
 */
function emitBomb(string $name, string $bytes, array $entry): void
{
    global $manifest;

    file_put_contents(BOMB_OUT_DIR.'/'.$name.'.pdf', $bytes);
    $entry['name'] = $name;
    $entry['file'] = $name.'.pdf';
    $entry['bytes'] = strlen($bytes);
    $manifest[] = $entry;
    printf("  %-26s %7d bytes on disk\n", $name.'.pdf', strlen($bytes));
}

/**
 * A payload that deflates to almost nothing: the compression ratio is the weapon.
 *
 * Spaces are whitespace in a content stream, so the result is a syntactically valid — if
 * pointless — page description rather than a blob that only looks like one.
 */
function inflatable(int $decodedBytes): string
{
    return (string) gzcompress(str_repeat(' ', $decodedBytes), 9);
}

/** A minimal one-page document whose page content streams are the given payloads. */
function pageWithContents(PdfFixtureWriter $writer, array $contentObjs): int
{
    $pagesObj = $writer->reserve();
    $pageObj = $writer->add(sprintf(
        '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Rotate 0'
        .' /Resources << >> /Contents [%s] >>',
        $pagesObj,
        implode(' ', array_map(static fn (int $n): string => $n.' 0 R', $contentObjs)),
    ));
    $writer->put($pagesObj, '<< /Type /Pages /Kids ['.$pageObj.' 0 R] /Count 1 >>');

    return $writer->add('<< /Type /Catalog /Pages '.$pagesObj.' 0 R >>');
}

if (! is_dir(BOMB_OUT_DIR)) {
    mkdir(BOMB_OUT_DIR, 0o755, true);
}

echo "Generating PDF resource-exhaustion fixtures...\n";

// ---------------------------------------------------------------------------
// 1. Single-stream bomb: one Flate stream, ~12 MiB decoded, ~12 KB stored.
//
// Proves: the decompression ceiling is enforced at all, and the refusal names the
// ceiling instead of surfacing as a generic parse failure.
// ---------------------------------------------------------------------------
$writer = new PdfFixtureWriter;
$content = $writer->addStream('<< /Filter /FlateDecode >>', inflatable(12 * MIB));
$root = pageWithContents($writer, [$content]);
emitBomb('single-stream-bomb', $writer->build($root), [
    'description' => 'One page whose single FlateDecode content stream inflates to 12 MiB.',
    'expected_preflight' => 'reject',
    'expected_rejections' => ['decompression_limit_exceeded'],
    'decoded_bytes_if_unbounded' => 12 * MIB,
    'proves' => 'A single stream cannot inflate past the decompression ceiling, and the refusal is '
        .'a named, actionable finding rather than "unparseable".',
]);

// ---------------------------------------------------------------------------
// 2. Many-small-streams bomb: 16 Flate streams, 1 MiB each, ~16 MiB decoded.
//
// This is the fixture the whole issue is about. Every stream is comfortably under any
// sane *per-stream* ceiling, so the pre-fix code decoded and retained all 48 MiB from a
// ~17 KB upload. Only an aggregate budget refuses it.
// ---------------------------------------------------------------------------
$writer = new PdfFixtureWriter;
$contents = [];
for ($i = 0; $i < 16; $i++) {
    $contents[] = $writer->addStream('<< /Filter /FlateDecode >>', inflatable(1 * MIB));
}
$root = pageWithContents($writer, $contents);
emitBomb('many-small-streams-bomb', $writer->build($root), [
    'description' => '16 FlateDecode content streams of 1 MiB each: 16 MiB decoded in total, no single '
        .'stream anywhere near a per-stream ceiling.',
    'expected_preflight' => 'reject',
    'expected_rejections' => ['decompression_limit_exceeded'],
    'decoded_bytes_if_unbounded' => 16 * MIB,
    'per_stream_decoded_bytes' => MIB,
    'proves' => 'The budget is aggregate. A per-stream ceiling alone accepts this document and retains '
        .'16 MiB for it; the running total is what refuses it.',
]);

// ---------------------------------------------------------------------------
// 3. Deeply nested object graph: 400 levels of directly nested arrays.
//
// Proves the tokenizer fails closed on nesting rather than recursing until the stack or
// the memory limit decides. tc-lib-pdf-parser refuses past 256 levels.
// ---------------------------------------------------------------------------
$writer = new PdfFixtureWriter;
$writer->add(str_repeat('[', 400).'0'.str_repeat(']', 400));
$root = pageWithContents($writer, [$writer->addStream('<< >>', "q\nQ\n")]);
emitBomb('deep-nesting-bomb', $writer->build($root), [
    'description' => 'An indirect object holding 400 levels of directly nested arrays.',
    'expected_preflight' => 'reject',
    'expected_rejections' => ['unparseable'],
    'nesting_depth' => 400,
    'proves' => 'Nesting fails closed inside the parser (256-level ceiling) instead of recursing until '
        .'the stack or the memory limit decides.',
]);

// ---------------------------------------------------------------------------
// 4. Object-count bomb: a cross-reference stream declaring five million entries.
//
// The declaration costs 20 bytes of /Index. Building the entries it declares costs
// hundreds of megabytes, and the pre-fix code checked `max_objects` only after the parse
// had already done it.
// ---------------------------------------------------------------------------
(static function (): void {
    $declared = 5_000_000;

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Resources << >> >>',
    ];

    $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num." 0 obj\n".$body."\nendobj\n";
    }

    // Five rows of a /W [1 4 2] cross-reference stream: object 0 free, then the three
    // real objects, then the cross-reference stream itself.
    $xrefPos = strlen($pdf);
    $rows = chr(0).pack('N', 0).pack('n', 65535);
    foreach ([1, 2, 3] as $num) {
        $rows .= chr(1).pack('N', $offsets[$num]).pack('n', 0);
    }
    $rows .= chr(1).pack('N', $xrefPos).pack('n', 0);

    $dict = sprintf(
        '<< /Type /XRef /W [1 4 2] /Index [0 %d] /Size %d /Root 1 0 R'
        .' /ID [<00112233445566778899AABBCCDDEEFF> <00112233445566778899AABBCCDDEEFF>] /Length %d >>',
        $declared,
        $declared,
        strlen($rows),
    );
    $pdf .= "4 0 obj\n".$dict."\nstream\n".$rows."\nendstream\nendobj\n";
    $pdf .= "startxref\n".$xrefPos."\n%%EOF\n";

    emitBomb('object-count-bomb', $pdf, [
        'description' => 'A cross-reference stream whose /Index declares 5,000,000 entries from 20 bytes '
            .'of dictionary.',
        'expected_preflight' => 'reject',
        'expected_rejections' => ['object_limit_exceeded'],
        'declared_objects' => $declared,
        'proves' => 'The object ceiling is consulted while the cross-reference data is read, before the '
            .'entries it declares are built.',
    ]);
})();

// ---------------------------------------------------------------------------
// 5. Control: large but entirely legitimate.
//
// Forty pages of ordinary, repetitive contract prose. It decodes to ~1.6 MiB across 40
// Flate streams — far more than any other fixture in the repository, and it must still be
// accepted. A budget that rejects this one is set wrong.
// ---------------------------------------------------------------------------
(static function (): void {
    $boilerplate = [
        'This Agreement is entered into by the parties named in Schedule A hereto.',
        'Each party represents that it has full corporate power to enter into this Agreement.',
        'Neither party shall be liable for any indirect or consequential loss howsoever arising.',
        'This Agreement may be executed in counterparts, each of which is an original.',
        'Notices under this Agreement shall be given in writing to the addresses in Schedule B.',
        'The parties agree that the governing law is that of the jurisdiction named in Schedule C.',
        'No amendment to this Agreement is effective unless made in writing and signed by both parties.',
        'The provisions of this clause survive the termination or expiry of this Agreement.',
    ];

    $writer = new PdfFixtureWriter;
    $fontObj = $writer->add(
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding'
        .' /FirstChar 32 /LastChar 126 /Widths ['.trim(str_repeat('600 ', 95)).'] >>'
    );
    $resources = '<< /Font << /F1 '.$fontObj.' 0 R >> >>';

    $pagesObj = $writer->reserve();
    $kids = [];
    $decoded = 0;
    for ($page = 0; $page < 40; $page++) {
        $content = "q\n";
        for ($line = 0; $line < 480; $line++) {
            $content .= sprintf(
                "BT\n/F1 9.0 Tf\n%.4F %.4F Td\n(%s) Tj\nET\n",
                72.0 + ($line % 3) * 4.0,
                720.0 - ($line % 60) * 11.0,
                $boilerplate[($page * 480 + $line) % count($boilerplate)],
            );
        }
        $content .= "Q\n";
        $decoded += strlen($content);

        $contentObj = $writer->addStream('<< /Filter /FlateDecode >>', (string) gzcompress($content, 9));
        $kids[] = $writer->add(sprintf(
            '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Rotate 0'
            .' /Resources %s /Contents %d 0 R >>',
            $pagesObj,
            $resources,
            $contentObj,
        ));
    }

    $writer->put($pagesObj, sprintf(
        '<< /Type /Pages /Kids [%s] /Count %d >>',
        implode(' ', array_map(static fn (int $k): string => $k.' 0 R', $kids)),
        count($kids),
    ));
    $root = $writer->add('<< /Type /Catalog /Pages '.$pagesObj.' 0 R >>');

    emitBomb('large-legitimate-control', $writer->build($root), [
        'description' => '40 pages of ordinary contract prose across 40 FlateDecode content streams.',
        'expected_preflight' => 'accept',
        'expected_rejections' => [],
        'decoded_bytes_if_unbounded' => $decoded,
        'page_count' => 40,
        'proves' => 'The ceilings admit a document that is genuinely large. If this one is refused, the '
            .'budget is set wrong rather than the document being hostile.',
    ]);
})();

// ---------------------------------------------------------------------------
// 6. Not a bomb: a document whose *arithmetic* overflows rather than its size.
//
// Preflight reads the object graph — sizes, object counts, streams — and never interprets
// the operators inside a content stream. So this file passes every ceiling and costs
// nothing, and then the first thing that multiplies its transform matrix produces an
// infinite coordinate that no geometry type will accept.
//
// It belongs beside the bombs because it is the same shape of problem — an input that is
// cheap to admit and expensive to trust — and it is deliberately declared `accept`, so the
// corpus test pins the premise the extraction test rests on: preflight really does let this
// through.
// ---------------------------------------------------------------------------
(static function (): void {
    $writer = new PdfFixtureWriter;

    $fontObj = $writer->add(
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding'
        .' /FirstChar 32 /LastChar 126 /Widths ['.trim(str_repeat('600 ', 95)).'] >>'
    );

    // Two transforms, each already at the top of the double range. One alone yields a finite,
    // absurd coordinate, which is a different case and a legitimate one; their product is
    // infinite, which is the case no rectangle can hold.
    $enormous = str_repeat('9', 300);
    $transform = $enormous.' 0 0 '.$enormous.' 0 0 cm ';

    $contentObj = $writer->addStream(
        '<< >>',
        "q\n".$transform.$transform."BT\n/F1 12.0 Tf\n10.0 10.0 Td\n(Signature:) Tj\nET\nQ\n"
    );

    $pagesObj = $writer->reserve();
    $pageObj = $writer->add(sprintf(
        '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /CropBox [0 0 612 792] /Rotate 0'
        .' /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
        $pagesObj,
        $fontObj,
        $contentObj,
    ));
    $writer->put($pagesObj, '<< /Type /Pages /Kids ['.$pageObj.' 0 R] /Count 1 >>');
    $root = $writer->add('<< /Type /Catalog /Pages '.$pagesObj.' 0 R >>');

    emitBomb('overflowing-transform', $writer->build($root), [
        'description' => 'One page whose CTM is multiplied past the double range before text is shown.',
        'expected_preflight' => 'accept',
        'expected_rejections' => [],
        'decoded_bytes_if_unbounded' => 0,
        'page_count' => 1,
        'proves' => 'Preflight bounds the document, not the arithmetic inside it. Text extraction is '
            .'where an impossible transform is caught, and it has to leave as an unreadable document '
            .'rather than as an uncaught geometry error.',
    ]);
})();

file_put_contents(
    BOMB_OUT_DIR.'/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

echo '  manifest.json written with '.count($manifest)." entries\n";
