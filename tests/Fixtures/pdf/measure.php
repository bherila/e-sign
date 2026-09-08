<?php

declare(strict_types=1);

/**
 * Per-fixture cost of preflight, import and text extraction.
 *
 * Run with:  php tests/Fixtures/pdf/measure.php
 *
 * Emits the markdown table pasted into docs/stage0/pdf-import.md. Numbers are wall
 * clock and peak RSS delta on one machine, so they are useful as ratios and as an
 * order of magnitude, not as an absolute budget.
 */

use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\UnsupportedSourceException;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\TcPdf\TcPdfAssembler;
use App\Domain\Preparation\TcPdf\TcPdfPreflight;
use App\Domain\Preparation\TcPdf\TcPdfTextLocator;
use Tests\Fixtures\Pdf\PdfFixtureWriter;

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/PdfFixtureWriter.php';

/** Median of a few runs, so a single GC pause does not become the headline number. */
function timed(callable $operation, int $iterations = 5): array
{
    $timings = [];
    $memory = 0;

    for ($i = 0; $i < $iterations; $i++) {
        memory_reset_peak_usage();
        $before = memory_get_peak_usage(true);
        $start = hrtime(true);
        $operation();
        $timings[] = (hrtime(true) - $start) / 1_000_000.0;
        $memory = max($memory, memory_get_peak_usage(true) - $before);
    }

    sort($timings);

    return [$timings[intdiv(count($timings), 2)], $memory];
}

$directory = __DIR__;
/** @var array<int, array<string, mixed>> $manifest */
$manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);

$preflight = new TcPdfPreflight;
$assembler = new TcPdfAssembler;
$locator = new TcPdfTextLocator;

echo "| Fixture | Bytes | Pages | Objects | Preflight (ms) | Assemble (ms) | Extract (ms) | Peak RSS delta |\n";
echo "|---|---:|---:|---:|---:|---:|---:|---:|\n";

foreach ($manifest as $entry) {
    /** @var string $name */
    $name = $entry['name'];
    $bytes = (string) file_get_contents($directory.'/'.$entry['file']);

    [$preflightMs] = timed(static fn () => $preflight->inspect($bytes));
    $report = $preflight->inspect($bytes);

    $assembleMs = null;
    $extractMs = null;
    $peak = 0;

    if ($report->isAccepted()) {
        $overlays = [];
        foreach ($entry['pages'] as $page) {
            [$x, $y, $width, $height] = $page['overlay_native'];
            $overlays[] = new OverlayRectangle(
                (int) $page['page'],
                new NativeRect((float) $x, (float) $y, (float) $width, (float) $height),
            );
        }

        try {
            [$assembleMs, $assemblePeak] = timed(static fn () => $assembler->assemble($bytes, $overlays));
            [$extractMs, $extractPeak] = timed(static fn () => $locator->extract($bytes));
            $peak = max($assemblePeak, $extractPeak);
        } catch (UnsupportedSourceException) {
            $assembleMs = null;
        }
    }

    printf(
        "| `%s` | %s | %d | %d | %.2f | %s | %s | %s |\n",
        $name,
        number_format(strlen($bytes)),
        count($report->pages),
        $report->metrics->objectCount,
        $preflightMs,
        $assembleMs === null ? 'rejected' : sprintf('%.2f', $assembleMs),
        $extractMs === null ? 'n/a' : sprintf('%.2f', $extractMs),
        $peak === 0 ? '< 2 MB' : number_format($peak / 1_048_576, 1).' MB',
    );
}

// ---------------------------------------------------------------------------
// Scaling probe. The committed fixtures are deliberately tiny, which says nothing
// about where preflight limits belong, so this section builds throwaway documents
// of increasing size in memory and measures them. Nothing here is written to disk.
// ---------------------------------------------------------------------------

/** A synthetic contract-shaped document: $pages Letter pages with 40 lines of text each. */
function syntheticDocument(int $pages): string
{
    $writer = new PdfFixtureWriter;
    $font = $writer->add(
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding'
        .' /FirstChar 32 /LastChar 126 /Widths ['.trim(str_repeat('600 ', 95)).'] >>',
    );
    $resources = '<< /Font << /F1 '.$font.' 0 R >> >>';

    $pagesObj = $writer->reserve();
    $kids = [];
    for ($page = 1; $page <= $pages; $page++) {
        $content = '';
        for ($line = 0; $line < 40; $line++) {
            $content .= sprintf(
                "BT\n/F1 11.0 Tf\n72.0000 %.4F Td\n(Clause %d.%d of this synthetic agreement, for measurement only.) Tj\nET\n",
                720.0 - $line * 16.0,
                $page,
                $line + 1,
            );
        }
        $contentObj = $writer->addStream('<< >>', $content);
        $kids[] = $writer->add(sprintf(
            '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 612 792] /Resources %s /Contents %d 0 R >>',
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

    return $writer->build($writer->add('<< /Type /Catalog /Pages '.$pagesObj.' 0 R >>'));
}

echo "\n| Synthetic pages | Bytes | Preflight (ms) | Assemble (ms) | Extract (ms) | Text runs | Peak RSS delta |\n";
echo "|---:|---:|---:|---:|---:|---:|---:|\n";

foreach ([1, 10, 50, 200] as $pageCount) {
    $bytes = syntheticDocument($pageCount);

    [$preflightMs] = timed(static fn () => $preflight->inspect($bytes), 3);
    [$assembleMs, $assemblePeak] = timed(static fn () => $assembler->assemble($bytes), 3);
    [$extractMs, $extractPeak] = timed(static fn () => $locator->extract($bytes), 3);

    printf(
        "| %d | %s | %.1f | %.1f | %.1f | %d | %s |\n",
        $pageCount,
        number_format(strlen($bytes)),
        $preflightMs,
        $assembleMs,
        $extractMs,
        count($locator->extract($bytes)),
        number_format(max($assemblePeak, $extractPeak) / 1_048_576, 1).' MB',
    );
}
