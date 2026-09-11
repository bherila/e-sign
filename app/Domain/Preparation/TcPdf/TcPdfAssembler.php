<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf;

use App\Domain\Preparation\Assembly\AssembledDocument;
use App\Domain\Preparation\Assembly\AssemblyException;
use App\Domain\Preparation\Assembly\OverlayImage;
use App\Domain\Preparation\Assembly\OverlayRectangle;
use App\Domain\Preparation\Assembly\OverlayText;
use App\Domain\Preparation\Assembly\PageOverlay;
use App\Domain\Preparation\Assembly\UnsupportedSourceException;
use App\Domain\Preparation\Contracts\PdfAssembler;
use App\Domain\Preparation\Contracts\PdfPreflight;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\Parsing\PageTreeReader;
use App\Domain\Preparation\TcPdf\Parsing\PdfObjectGraph;
use Com\Tecnick\Pdf\Exception;
use Com\Tecnick\Pdf\Import\ImportException;
use Com\Tecnick\Pdf\Import\ImportUnsupportedFeatureException;
use Com\Tecnick\Pdf\Parser\Parser;
use Com\Tecnick\Pdf\Tcpdf;
use InvalidArgumentException;
use Throwable;

/**
 * Imports every page of a source PDF as a form XObject and writes a new document with
 * overlays placed in native coordinates.
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
 *
 * ## Appended documents
 *
 * `assemble()` also takes whole PDFs to append after the source's pages. They are imported
 * the same way and numbered after it, so the completion report the Evidence module renders
 * is a page of the executed document rather than a second file stapled to it in a viewer.
 * Each appended document goes through preflight too: an unsafe document must not reach the
 * importer by a different door.
 */
final readonly class TcPdfAssembler implements PdfAssembler
{
    /**
     * @param  PdfPreflight|null  $preflight  Runs before import and refuses anything it rejects.
     *                                        Pass null only to observe raw engine behaviour in tests.
     * @param  string|null  $fontDirectory  Where the bundled text metrics live. Null resolves to
     *                                      the repository's `resources/fonts`, which is what the
     *                                      service provider passes explicitly.
     * @param  PreflightLimits  $limits  This deployment's ceilings. Every read of one input
     *                                   document — its geometry and its import — is charged to one
     *                                   budget built from them.
     * @param  (\Closure(): float)|null  $clock  Handed to every budget this adapter builds, as
     *                                           {@see PreflightBudget} takes it: so a test can see
     *                                           the import consult the budget without sleeping.
     *
     * @throws InvalidArgumentException When the per-stream ceiling is one the import cannot keep.
     */
    public function __construct(
        private ?PdfPreflight $preflight = new TcPdfPreflight,
        private ?string $fontDirectory = null,
        private PreflightLimits $limits = new PreflightLimits,
        private ?\Closure $clock = null,
    ) {
        // The import engine parses its source again with a parser this adapter cannot reach:
        // tc-lib-pdf builds it inside `SourceDocument`, and keeps only `decode_streams` and
        // `ignore_filter_errors` from the options it is given, so neither a budget nor this
        // deployment's configuration gets through. That parser decodes what it must under a fixed
        // per-stream ceiling. A deployment ceiling above it — or 0, "no per-stream ceiling" — would
        // accept a document at upload and have the import refuse the same bytes. A ceiling this
        // adapter cannot keep is refused where it is configured, not on the first document to need
        // it.
        if ($limits->maxDecodedStreamBytes <= 0 || $limits->maxDecodedStreamBytes > Parser::DEFAULT_MAX_STREAM_SIZE) {
            throw new InvalidArgumentException(sprintf(
                'max_decoded_stream_bytes is %d; it must be between 1 and %d. The PDF import engine re-reads '
                .'every document under that fixed per-stream ceiling, so any other value would admit documents '
                .'that assembly then refuses.',
                $limits->maxDecodedStreamBytes,
                Parser::DEFAULT_MAX_STREAM_SIZE,
            ));
        }
    }

    /**
     * @param  array<int, PageOverlay>  $overlays
     * @param  array<int, string>  $appendedDocuments
     */
    public function assemble(string $pdfBytes, array $overlays = [], array $appendedDocuments = []): AssembledDocument
    {
        $this->assertAcceptable($pdfBytes);

        foreach ($appendedDocuments as $appended) {
            $this->assertAcceptable($appended);
        }

        $startedAt = microtime(true);
        memory_reset_peak_usage();
        $memoryBefore = memory_get_peak_usage(true);

        // One budget per input document, shared by every read of it: the geometry read here and
        // the import in `writePages()`. The output is this adapter's own product and is read back
        // under a budget of its own.
        $sourceBudget = $this->budget();
        $sourcePages = $this->readGeometry($pdfBytes, $sourceBudget);
        if ($sourcePages === []) {
            throw new AssemblyException('The source document does not contain any pages.');
        }

        /** @var array<int, array<int, PageOverlay>> $byPage */
        $byPage = [];
        foreach ($overlays as $overlay) {
            $byPage[$overlay->pageNumber()][] = $overlay;
        }

        if ($this->needsText($overlays)) {
            CoreFontMetrics::install($this->fontDirectory ?? dirname(__DIR__, 4).'/resources/fonts');
        }

        $pdf = new Tcpdf('pt', true, false, true);

        try {
            $written = 0;

            $documents = [[$pdfBytes, $sourcePages, $sourceBudget], ...$this->appendedWithGeometry($appendedDocuments)];
            foreach ($documents as [$bytes, $geometry, $budget]) {
                $written = $this->writePages($pdf, $bytes, $geometry, $byPage, $written, $budget);
            }

            $result = $pdf->getOutPDFString();
            $warnings = $pdf->getWarnings();
        } catch (AssemblyException $exception) {
            throw $exception;
        } catch (PreflightBudgetException $exception) {
            // Outside this port's contract, like every other failure below, so it leaves as the
            // failure the port promises. The message still names the ceiling.
            throw new AssemblyException(
                'The document could not be re-assembled within this deployment\'s limits: '.$exception->getMessage(),
                previous: $exception,
            );
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
            $result,
            $sourcePages,
            $this->readGeometry($result, $this->budget()),
            array_values($warnings),
            microtime(true) - $startedAt,
            max(0, memory_get_peak_usage(true) - $memoryBefore),
        );
    }

    /**
     * @throws UnsupportedSourceException
     */
    private function assertAcceptable(string $pdfBytes): void
    {
        if (! $this->preflight instanceof PdfPreflight) {
            return;
        }

        $report = $this->preflight->inspect($pdfBytes);

        if (! $report->isAccepted()) {
            throw new UnsupportedSourceException($report->rejectionMessage());
        }
    }

    /**
     * @param  array<int, string>  $appendedDocuments
     * @return list<array{string, array<int, PageGeometry>, PreflightBudget}>
     */
    private function appendedWithGeometry(array $appendedDocuments): array
    {
        return array_values(array_map(
            function (string $bytes): array {
                $budget = $this->budget();

                return [$bytes, $this->readGeometry($bytes, $budget), $budget];
            },
            $appendedDocuments,
        ));
    }

    /** A fresh budget for one document, on this deployment's limits. */
    private function budget(): PreflightBudget
    {
        return new PreflightBudget($this->limits, $this->clock);
    }

    /**
     * Import one document's pages onto the end of the output, drawing the overlays that
     * address them.
     *
     * @param  array<int, PageGeometry>  $geometry
     * @param  array<int, array<int, PageOverlay>>  $byPage
     * @param  int  $written  Output pages already written.
     * @param  PreflightBudget  $budget  The document's, already charged for reading its geometry.
     * @return int Output pages written after this document.
     *
     * @throws PreflightBudgetException
     */
    private function writePages(Tcpdf $pdf, string $bytes, array $geometry, array $byPage, int $written, PreflightBudget $budget): int
    {
        // Pinned rather than left to the engine's default: the import's own parse must not decode
        // page content, because nothing can charge it for that. What the importer does decode —
        // whatever its parser needs to read the object graph, and a page's /Contents array when it
        // joins one — is these same bytes through the same filters that the geometry read decoded
        // under this budget, at a per-stream ceiling the constructor keeps equal to the engine's.
        $sourceId = $pdf->setImportSourceData($bytes, ['decode_streams' => false]);
        $pageCount = $pdf->getSourcePageCount($sourceId);

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            // The import is engine work this adapter cannot meter from inside, so the document's
            // backstops are consulted between its units: one imported page.
            $budget->tick();

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

            [$dx, $dy] = $this->boxOriginCompensation($geometry[$pageNumber - 1] ?? null);
            $pdf->useImportedPage($template, $dx, -$dy, $width, $height, ['keepAspectRatio' => false]);

            $written++;

            foreach ($byPage[$written] ?? [] as $overlay) {
                $pdf->page->addContent($this->overlayContent($pdf, $overlay, $height));
            }
        }

        return $written;
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
     * Emit one overlay's content on the current output page.
     *
     * The output page has no /Rotate and a CropBox at the origin, so native (x, y) maps
     * to user space (x, pageHeight - y). tc-lib-pdf's raw helpers already measure y
     * downwards from the top of the page, so the native values go in directly.
     */
    private function overlayContent(Tcpdf $pdf, PageOverlay $overlay, float $pageHeight): string
    {
        return match (true) {
            $overlay instanceof OverlayRectangle => $this->rectangleContent($pdf, $overlay),
            $overlay instanceof OverlayText => $this->textContent($pdf, $overlay),
            $overlay instanceof OverlayImage => $this->imageContent($pdf, $overlay, $pageHeight),
            default => throw new AssemblyException(
                'Unsupported overlay type '.$overlay::class.'. An overlay that cannot be drawn is '
                .'refused rather than silently omitted from the document.',
            ),
        };
    }

    private function rectangleContent(Tcpdf $pdf, OverlayRectangle $overlay): string
    {
        return 'q'."\n".$pdf->graph->getBasicRect(
            $overlay->rect->x,
            $overlay->rect->y,
            $overlay->rect->width,
            $overlay->rect->height,
            'S',
            ['lineWidth' => $overlay->lineWidth, 'lineColor' => $this->hexColour($overlay->strokeRgb)],
        )."\nQ\n";
    }

    /**
     * Draw one line of text with its baseline inside the overlay's rectangle.
     *
     * With no explicit baseline the cap-height box is centred vertically, which puts a
     * single-line field value where a reader expects it inside the box the sender drew.
     */
    private function textContent(Tcpdf $pdf, OverlayText $overlay): string
    {
        if ($overlay->text === '') {
            return '';
        }

        $pdf->font->insert($pdf->pon, CoreFontMetrics::FAMILY, '', $overlay->fontSize);

        $baseline = $overlay->baselineOffset
            ?? (($overlay->rect->height + ($overlay->fontSize * CoreFontMetrics::CAP_HEIGHT_RATIO)) / 2);

        [$red, $green, $blue] = $overlay->fillRgb;

        return 'q'."\n"
            .sprintf('%F %F %F rg', $this->clamp($red), $this->clamp($green), $this->clamp($blue))."\n"
            .$pdf->getTextLine($overlay->text, $overlay->rect->x, $overlay->rect->y + $baseline)
            ."\nQ\n";
    }

    /**
     * Place an image inside the overlay's rectangle, fitted and centred.
     *
     * The aspect ratio is preserved: a signature stretched to a field's proportions is not
     * the mark the person drew.
     */
    private function imageContent(Tcpdf $pdf, OverlayImage $overlay, float $pageHeight): string
    {
        $size = @getimagesizefromstring($overlay->bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new AssemblyException(
                'An image overlay carried bytes that are not a readable raster image, so the mark '
                .'could not be drawn. The document is refused rather than published without it.',
            );
        }

        $scale = min($overlay->rect->width / $size[0], $overlay->rect->height / $size[1]);
        $width = $size[0] * $scale;
        $height = $size[1] * $scale;

        try {
            $imageId = $pdf->image->add('@'.$overlay->bytes);
        } catch (Throwable $exception) {
            throw new AssemblyException(
                'An image overlay could not be embedded: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return $pdf->image->getSetImage(
            $imageId,
            $overlay->rect->x + (($overlay->rect->width - $width) / 2),
            $overlay->rect->y + (($overlay->rect->height - $height) / 2),
            $width,
            $height,
            $pageHeight,
        );
    }

    /**
     * @param  array<int, PageOverlay>  $overlays
     */
    private function needsText(array $overlays): bool
    {
        foreach ($overlays as $overlay) {
            if ($overlay instanceof OverlayText) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{float, float, float}  $rgb
     */
    private function hexColour(array $rgb): string
    {
        return sprintf(
            '#%02X%02X%02X',
            (int) round($this->clamp($rgb[0]) * 255),
            (int) round($this->clamp($rgb[1]) * 255),
            (int) round($this->clamp($rgb[2]) * 255),
        );
    }

    private function clamp(float $component): float
    {
        return max(0.0, min(1.0, $component));
    }

    /**
     * @return array<int, PageGeometry>
     */
    private function readGeometry(string $pdfBytes, PreflightBudget $budget): array
    {
        // The document's budget, from the configured limits. This is a second read of bytes
        // preflight has already inspected, and it was the one site that parsed without any budget
        // at all and walked to the page-tree reader's built-in ceiling — so a deployment that
        // raised `max_pages` could import a document and then fail to read its geometry.
        try {
            $graph = PdfObjectGraph::parse($pdfBytes, $budget);

            return array_map(
                static fn ($page): PageGeometry => $page->geometry,
                (new PageTreeReader($graph))->pages($budget->limits->maxPages),
            );
        } catch (Throwable $exception) {
            throw new AssemblyException('Page geometry could not be read: '.$exception->getMessage(), previous: $exception);
        }
    }
}
