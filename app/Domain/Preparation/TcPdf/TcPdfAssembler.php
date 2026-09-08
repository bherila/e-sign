<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\UnsupportedSourceException;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Preparation\TcPdf\Parsing\PageTreeReader;
use App\Domain\Preparation\TcPdf\Parsing\PdfObjectGraph;
use Com\Tecnick\Pdf\Exception;
use Com\Tecnick\Pdf\Import\ImportException;
use Com\Tecnick\Pdf\Import\ImportUnsupportedFeatureException;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * Imports every page of a source PDF as a form XObject and writes a new document with
 * overlay rectangles placed in native coordinates.
 *
 * Two engine behaviours are compensated for here, both documented in
 * docs/stage0/pdf-import.md rather than hidden:
 *
 *  1. **Page-box origin.** tc-lib-pdf 8.73 builds the form XObject /Matrix from the box
 *     width and height only. When the chosen box has a nonzero origin the imported
 *     content lands displaced by that origin and is clipped by the destination page.
 *     `boxOriginCompensation()` applies the missing translation through the placement
 *     matrix, which is a documented, tested adjustment in this adapter, not a change to
 *     the library.
 *
 *  2. **Rotation is baked in.** `respectRotation` folds /Rotate into the form matrix and
 *     swaps the page dimensions, so the output page carries no /Rotate. That is what we
 *     want: the output page's user space and the native space then differ only by the
 *     y-axis flip, which is why an overlay can be emitted with a single subtraction.
 *
 * /UserUnit is *not* carried through by the engine and is not reconstructed here; the
 * loss is reported by preflight instead.
 */
final readonly class TcPdfAssembler implements PdfAssembler
{
    /**
     * @param  PdfPreflight|null  $preflight  Runs before import and refuses anything it rejects.
     *                                        Pass null only to observe raw engine behaviour in tests.
     */
    public function __construct(private ?PdfPreflight $preflight = new TcPdfPreflight) {}

    /**
     * @param  array<int, OverlayRectangle>  $overlays
     */
    public function assemble(string $pdfBytes, array $overlays = []): AssembledDocument
    {
        if ($this->preflight instanceof PdfPreflight) {
            $report = $this->preflight->inspect($pdfBytes);
            if (! $report->isAccepted()) {
                throw new UnsupportedSourceException($report->rejectionMessage());
            }
        }

        $startedAt = microtime(true);
        memory_reset_peak_usage();
        $memoryBefore = memory_get_peak_usage(true);

        $sourcePages = $this->readGeometry($pdfBytes);
        if ($sourcePages === []) {
            throw new AssemblyException('The source document does not contain any pages.');
        }

        /** @var array<int, array<int, OverlayRectangle>> $byPage */
        $byPage = [];
        foreach ($overlays as $overlay) {
            $byPage[$overlay->page][] = $overlay;
        }

        $pdf = new Tcpdf('pt', true, false, true);

        try {
            $sourceId = $pdf->setImportSourceData($pdfBytes);
            $pageCount = $pdf->getSourcePageCount($sourceId);

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($sourceId, $pageNumber, [
                    'box' => 'CropBox',
                    'respectRotation' => true,
                    'groupXObject' => true,
                    'cache' => false,
                ]);

                $width = $template->getWidth();
                $height = $template->getHeight();

                $pdf->addPage([
                    'format' => '',
                    'width' => $width,
                    'height' => $height,
                    'orientation' => $width > $height ? 'L' : 'P',
                ]);

                [$dx, $dy] = $this->boxOriginCompensation($sourcePages[$pageNumber - 1] ?? null);
                $pdf->useImportedPage($template, $dx, -$dy, $width, $height, ['keepAspectRatio' => false]);

                foreach ($byPage[$pageNumber] ?? [] as $overlay) {
                    $pdf->page->addContent($this->overlayContent($pdf, $overlay));
                }
            }

            $bytes = $pdf->getOutPDFString();
            $warnings = $pdf->getWarnings();
        } catch (ImportUnsupportedFeatureException $exception) {
            throw new UnsupportedSourceException(
                'The import engine refused this document: '.$exception->getMessage(),
                previous: $exception,
            );
        } catch (ImportException|Exception $exception) {
            throw new AssemblyException(
                'The document could not be re-assembled: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return new AssembledDocument(
            $bytes,
            $sourcePages,
            $this->readGeometry($bytes),
            array_values($warnings),
            microtime(true) - $startedAt,
            max(0, memory_get_peak_usage(true) - $memoryBefore),
        );
    }

    /**
     * The translation, in destination user space, that the engine's form /Matrix is
     * missing when the imported box origin is not (0, 0).
     *
     * Derived from the four correct matrices:
     *   rot   0: [ 1  0  0  1 -x0 -y0]   engine emits [ 1  0  0  1   0   0]
     *   rot  90: [ 0 -1  1  0 -y0  x1]   engine emits [ 0 -1  1  0   0   w]
     *   rot 180: [-1  0  0 -1  x1  y1]   engine emits [-1  0  0 -1   w   h]
     *   rot 270: [ 0  1 -1  0  y1 -x0]   engine emits [ 0  1 -1  0   h   0]
     * where w = x1 - x0 and h = y1 - y0.
     *
     * @return array{float, float}
     */
    private function boxOriginCompensation(?PageGeometry $page): array
    {
        if (! $page instanceof PageGeometry) {
            return [0.0, 0.0];
        }

        $x0 = $page->cropBox->x0;
        $y0 = $page->cropBox->y0;

        return match ($page->rotation) {
            PageRotation::None => [-$x0, -$y0],
            PageRotation::Clockwise90 => [-$y0, $x0],
            PageRotation::Clockwise180 => [$x0, $y0],
            PageRotation::Clockwise270 => [$y0, -$x0],
        };
    }

    /**
     * Emit a stroked rectangle at a native coordinate on the current output page.
     *
     * The output page has no /Rotate and a CropBox at the origin, so native (x, y) maps
     * to user space (x, pageHeight - y). tc-lib-pdf's raw rectangle helper already
     * measures y downwards from the top of the page, so the native values go in directly.
     */
    private function overlayContent(Tcpdf $pdf, OverlayRectangle $overlay): string
    {
        [$red, $green, $blue] = $overlay->strokeRgb;
        $colour = sprintf(
            '#%02X%02X%02X',
            (int) round(max(0.0, min(1.0, $red)) * 255),
            (int) round(max(0.0, min(1.0, $green)) * 255),
            (int) round(max(0.0, min(1.0, $blue)) * 255),
        );

        return 'q'."\n".$pdf->graph->getBasicRect(
            $overlay->rect->x,
            $overlay->rect->y,
            $overlay->rect->width,
            $overlay->rect->height,
            'S',
            ['lineWidth' => $overlay->lineWidth, 'lineColor' => $colour],
        )."\nQ\n";
    }

    /**
     * @return array<int, PageGeometry>
     */
    private function readGeometry(string $pdfBytes): array
    {
        try {
            $graph = PdfObjectGraph::parse($pdfBytes);

            return array_map(
                static fn ($page): PageGeometry => $page->geometry,
                (new PageTreeReader($graph))->pages(),
            );
        } catch (\Throwable $exception) {
            throw new AssemblyException('Page geometry could not be read: '.$exception->getMessage(), previous: $exception);
        }
    }
}
