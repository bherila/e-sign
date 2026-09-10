<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf;

use App\Domain\Preparation\Contracts\PdfTextLocator;
use App\Domain\Preparation\Geometry\CoordinateTransform;
use App\Domain\Preparation\Geometry\InvalidGeometryException;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Geometry\UserSpacePoint;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;
use App\Domain\Preparation\Preflight\PreflightLimits;
use App\Domain\Preparation\TcPdf\Parsing\ContentStreamOperation;
use App\Domain\Preparation\TcPdf\Parsing\ContentStreamTokenizer;
use App\Domain\Preparation\TcPdf\Parsing\FlattenedPage;
use App\Domain\Preparation\TcPdf\Parsing\FontDictionaryReader;
use App\Domain\Preparation\TcPdf\Parsing\MalformedPageTreeException;
use App\Domain\Preparation\TcPdf\Parsing\Matrix;
use App\Domain\Preparation\TcPdf\Parsing\PageTreeReader;
use App\Domain\Preparation\TcPdf\Parsing\PdfFontModel;
use App\Domain\Preparation\TcPdf\Parsing\PdfObjectGraph;
use App\Domain\Preparation\TcPdf\Parsing\TextState;
use App\Domain\Preparation\Text\TextDirection;
use App\Domain\Preparation\Text\TextExtractionException;
use App\Domain\Preparation\Text\TextRun;

/**
 * Positioned text extraction by interpreting page content streams.
 *
 * The implementation runs the text-object state machine of ISO 32000-1 9.4: it tracks
 * the CTM, the text matrix, the text line matrix, font, size, character and word
 * spacing, horizontal scaling, leading and rise, and computes each shown string's
 * origin and advance from real font metrics.
 *
 * What it deliberately does not do:
 *  - no regular expressions over PDF bytes, compressed or not;
 *  - no merging of adjacent runs into words, lines or paragraphs;
 *  - no fuzzy or model-assisted matching.
 *
 * One run per text-showing operator. A producer that splits a visible line into several
 * operators produces several runs, and an anchor that spans the seam will not match.
 * That is a real limitation and it is recorded in docs/stage0/pdf-import.md rather than
 * papered over with heuristics.
 */
final readonly class TcPdfTextLocator implements PdfTextLocator
{
    /** Guards against a content stream with an unbounded q/Q stack. */
    private const MAX_GRAPHICS_DEPTH = 64;

    /** Guards against form XObjects that reference each other. */
    private const MAX_XOBJECT_DEPTH = 8;

    /**
     * @param  PreflightLimits  $limits  The ceilings this deployment reads documents under, used
     *                                   when a caller supplies no budget of its own. Injected
     *                                   rather than defaulted in the body so the container can
     *                                   hand over the configured set: a deployment that raised a
     *                                   ceiling and then had extraction apply the built-in one
     *                                   would accept a document at upload and refuse the same
     *                                   bytes on the next request.
     */
    public function __construct(private PreflightLimits $limits = new PreflightLimits) {}

    /**
     * @return array<int, TextRun>
     */
    public function extract(string $pdfBytes, ?int $page = null, ?PreflightBudget $budget = null): array
    {
        // One budget for this read, and every ceiling applied below comes from it. When the
        // caller supplies none this method owns one, which is not the same as being unbounded:
        // it means nobody outside is accounting for the cost, so nobody outside is told about it
        // either. See the catch below.
        $ceiling = $budget ?? new PreflightBudget($this->limits);

        try {
            $graph = $this->parse($pdfBytes, $ceiling);

            if ($graph->isEncrypted()) {
                throw new TextExtractionException('Text cannot be extracted from an encrypted document.');
            }

            $runs = [];

            // The configured page ceiling, not the page-tree reader's own default. Preflight
            // already admits documents up to this number; walking to a smaller hard-coded one
            // would refuse a document the deployment accepted, and do it with a page-tree error
            // rather than a limit anybody can act on.
            foreach ((new PageTreeReader($graph))->pages($ceiling->limits->maxPages) as $flattened) {
                $pageNumber = $flattened->geometry->pageNumber;
                if ($page !== null && $pageNumber !== $page) {
                    continue;
                }

                foreach ($this->extractPage($graph, $flattened, $ceiling) as $run) {
                    $runs[] = $run;
                }
            }

            return $runs;
        } catch (PreflightBudgetException $exhausted) {
            // Whose ceiling it was decides who hears about it. A caller that supplied a budget
            // opted into accounting for this document's cost and can tell "too expensive" from
            // "broken" — which are different answers for whoever uploaded it. A caller that
            // supplied none cannot: it never asked to be told, and handing it an exception it
            // does not catch turns a bounded refusal into an unhandled error.
            if ($budget instanceof PreflightBudget) {
                throw $exhausted;
            }

            throw new TextExtractionException(
                'The document could not be read within this deployment\'s limits.',
                previous: $exhausted,
            );
        } catch (MalformedPageTreeException|InvalidGeometryException $unreadable) {
            // Neither is a ceiling and neither is a parse failure: a page tree that does not
            // describe pages, or a content-stream transform whose product is not a finite
            // coordinate. Preflight reads the object graph and never the arithmetic inside a
            // stream, so an accepted document can still arrive here — and both types are
            // outside what this method promises, so they would escape every caller that catches
            // what it says it throws.
            throw new TextExtractionException(
                'The document\'s pages could not be read: '.$unreadable->getMessage(),
                previous: $unreadable,
            );
        }
    }

    /**
     * @throws TextExtractionException
     * @throws PreflightBudgetException
     */
    private function parse(string $pdfBytes, PreflightBudget $budget): PdfObjectGraph
    {
        try {
            return PdfObjectGraph::parse($pdfBytes, $budget);
        } catch (PreflightBudgetException $exhausted) {
            // Answered by the caller of `extract()`, which knows whose budget this was. Letting
            // the catch below flatten it would report a decompression bomb as a corrupt file.
            throw $exhausted;
        } catch (\Throwable $exception) {
            throw new TextExtractionException('The document could not be parsed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<int, TextRun>
     */
    private function extractPage(PdfObjectGraph $graph, FlattenedPage $flattened, PreflightBudget $budget): array
    {
        $content = $graph->contentStream($flattened->dictionary);
        if ($content === '') {
            return [];
        }

        $runs = [];
        $this->walk(
            $graph,
            $content,
            $flattened->resources,
            Matrix::identity(),
            new CoordinateTransform($flattened->geometry),
            $flattened->geometry->pageNumber,
            $runs,
            0,
            $budget,
        );

        return $runs;
    }

    /**
     * Interpret one content stream, recursing into placed form XObjects.
     *
     * Form XObjects matter here because that is exactly how an assembled document
     * stores its imported pages: extracting only the page-level stream would report
     * that a re-assembled contract has no text at all.
     *
     * @param  array<string, array<int, mixed>>  $resources
     * @param  array<int, TextRun>  $runs
     */
    private function walk(
        PdfObjectGraph $graph,
        string $content,
        array $resources,
        Matrix $baseCtm,
        CoordinateTransform $transform,
        int $pageNumber,
        array &$runs,
        int $depth,
        PreflightBudget $budget,
    ): void {
        if ($depth > self::MAX_XOBJECT_DEPTH) {
            throw new TextExtractionException(
                'Page '.$pageNumber.' nests form XObjects more than '.self::MAX_XOBJECT_DEPTH.' levels deep.',
            );
        }

        $fonts = $this->loadFonts($graph, $resources);

        $state = new TextState;
        /** @var array<int, Matrix> $ctmStack */
        $ctmStack = [];
        $ctm = $baseCtm;

        foreach ((new ContentStreamTokenizer($content))->operations() as $operation) {
            // Charged per operation, which is the unit a pathological stream multiplies. One page
            // can hold millions of text-showing operators while satisfying every ceiling that
            // describes the document, so checking between pages — or even per produced run —
            // lets a single stream spend the whole budget before anything looks.
            $budget->tick();

            switch ($operation->operator) {
                case 'q':
                    if (count($ctmStack) < self::MAX_GRAPHICS_DEPTH) {
                        $ctmStack[] = $ctm;
                    }
                    break;

                case 'Q':
                    $ctm = array_pop($ctmStack) ?? $baseCtm;
                    break;

                case 'cm':
                    $values = $operation->trailingNumbers(6);
                    if ($values !== null) {
                        $ctm = Matrix::fromArray($values)->multiply($ctm);
                    }
                    break;

                case 'BT':
                    $state->textMatrix = Matrix::identity();
                    $state->lineMatrix = Matrix::identity();
                    break;

                case 'ET':
                    break;

                case 'Tf':
                    $state->fontResource = $operation->name(0) ?? '';
                    $state->fontSize = $operation->number(1, $state->fontSize);
                    break;

                case 'Tc':
                    $state->charSpacing = $operation->number(0);
                    break;

                case 'Tw':
                    $state->wordSpacing = $operation->number(0);
                    break;

                case 'Tz':
                    $state->horizontalScale = $operation->number(0, 100.0) / 100.0;
                    break;

                case 'TL':
                    $state->leading = $operation->number(0);
                    break;

                case 'Ts':
                    $state->rise = $operation->number(0);
                    break;

                case 'Td':
                    $values = $operation->trailingNumbers(2);
                    if ($values !== null) {
                        $state->translateLine($values[0], $values[1]);
                    }
                    break;

                case 'TD':
                    $values = $operation->trailingNumbers(2);
                    if ($values !== null) {
                        $state->leading = -$values[1];
                        $state->translateLine($values[0], $values[1]);
                    }
                    break;

                case 'Tm':
                    $values = $operation->trailingNumbers(6);
                    if ($values !== null) {
                        $state->textMatrix = Matrix::fromArray($values);
                        $state->lineMatrix = $state->textMatrix;
                    }
                    break;

                case 'T*':
                    $state->translateLine(0.0, -$state->leading);
                    break;

                case 'Tj':
                    $bytes = $operation->stringBytes(0);
                    if ($bytes !== null) {
                        $this->show($state, $ctm, $fonts, $transform, $pageNumber, [$bytes], $runs);
                    }
                    break;

                case "'":
                    $state->translateLine(0.0, -$state->leading);
                    $bytes = $operation->stringBytes(0);
                    if ($bytes !== null) {
                        $this->show($state, $ctm, $fonts, $transform, $pageNumber, [$bytes], $runs);
                    }
                    break;

                case '"':
                    $state->wordSpacing = $operation->number(0);
                    $state->charSpacing = $operation->number(1);
                    $state->translateLine(0.0, -$state->leading);
                    $bytes = $operation->stringBytes(2);
                    if ($bytes !== null) {
                        $this->show($state, $ctm, $fonts, $transform, $pageNumber, [$bytes], $runs);
                    }
                    break;

                case 'TJ':
                    $items = $operation->arrayOperand(0);
                    if ($items !== null) {
                        $this->showArray($state, $ctm, $fonts, $transform, $pageNumber, $items, $runs);
                    }
                    break;

                case 'Do':
                    $this->enterXObject($graph, $operation, $resources, $ctm, $transform, $pageNumber, $runs, $depth, $budget);
                    break;
            }
        }
    }

    /**
     * Recurse into a placed form XObject. Image XObjects carry no text and are skipped.
     *
     * @param  array<string, array<int, mixed>>  $resources
     * @param  array<int, TextRun>  $runs
     */
    private function enterXObject(
        PdfObjectGraph $graph,
        ContentStreamOperation $operation,
        array $resources,
        Matrix $ctm,
        CoordinateTransform $transform,
        int $pageNumber,
        array &$runs,
        int $depth,
        PreflightBudget $budget,
    ): void {
        $name = $operation->name(count($operation->operands) - 1);
        if ($name === null) {
            return;
        }

        $xobjects = $graph->dictEntryAsDictionary($resources, 'XObject') ?? [];
        $ref = $graph->dictEntryRef($xobjects, $name);
        if ($ref === null) {
            return;
        }

        $dict = $graph->dictionary($ref);
        if ($dict === null || $graph->dictEntryAsName($dict, 'Subtype') !== 'Form') {
            return;
        }

        $content = $graph->streamData($ref);
        if ($content === null || $content === '') {
            return;
        }

        $matrix = $graph->dictEntryAsNumbers($dict, 'Matrix') ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $inner = $graph->dictEntryAsDictionary($dict, 'Resources') ?? $resources;

        $this->walk(
            $graph,
            $content,
            $inner,
            count($matrix) === 6 ? Matrix::fromArray($matrix)->multiply($ctm) : $ctm,
            $transform,
            $pageNumber,
            $runs,
            $depth + 1,
            $budget,
        );
    }

    /**
     * @param  array<string, PdfFontModel>  $fonts
     * @param  array<int, string>  $strings
     * @param  array<int, TextRun>  $runs
     */
    private function show(
        TextState $state,
        Matrix $ctm,
        array $fonts,
        CoordinateTransform $transform,
        int $pageNumber,
        array $strings,
        array &$runs,
    ): void {
        $this->showArray(
            $state,
            $ctm,
            $fonts,
            $transform,
            $pageNumber,
            array_map(static fn (string $s): array => ['str', $s], $strings),
            $runs,
        );
    }

    /**
     * Emit one run for a TJ array or a single shown string.
     *
     * The whole array becomes a single run: its elements are one visual piece of text
     * that a producer split only to apply kerning, and the numeric adjustments are
     * folded into the advance rather than into separate runs.
     *
     * @param  array<string, PdfFontModel>  $fonts
     * @param  array<int, array{string, mixed}>  $items
     * @param  array<int, TextRun>  $runs
     */
    private function showArray(
        TextState $state,
        Matrix $ctm,
        array $fonts,
        CoordinateTransform $transform,
        int $pageNumber,
        array $items,
        array &$runs,
    ): void {
        $font = $fonts[$state->fontResource] ?? null;
        if (! $font instanceof PdfFontModel) {
            throw new TextExtractionException(
                'Page '.$pageNumber.' shows text with font /'.$state->fontResource
                .', which is not in the page /Resources.',
            );
        }

        $text = '';
        $advance = 0.0;

        foreach ($items as $item) {
            if ($item[0] === 'num' && is_float($item[1])) {
                $advance -= $item[1] / 1000.0 * $state->fontSize * $state->horizontalScale;

                continue;
            }

            if (! in_array($item[0], ['str', 'hex'], true) || ! is_string($item[1])) {
                continue;
            }

            $codes = $font->codes($item[1]);
            $text .= $font->decode($codes);

            foreach ($codes as $code) {
                $glyphWidth = $font->width($code) / 1000.0 * $state->fontSize;
                $wordSpacing = (! $font->composite && $code === 32) ? $state->wordSpacing : 0.0;
                $advance += ($glyphWidth + $state->charSpacing + $wordSpacing) * $state->horizontalScale;
            }
        }

        if ($text !== '') {
            $runs[] = $this->buildRun($state, $ctm, $font, $transform, $pageNumber, $text, $advance);
        }

        $state->textMatrix = Matrix::translation($advance, 0.0)->multiply($state->textMatrix);
    }

    /**
     * Turn the current text position and the run's advance into a native rectangle.
     *
     * The run box is the advance width by the font's declared em box (ascent above the
     * baseline, descent below), which is what a person points at when they say "put the
     * signature under this line". It is not a tight glyph bounding box.
     */
    private function buildRun(
        TextState $state,
        Matrix $ctm,
        PdfFontModel $font,
        CoordinateTransform $transform,
        int $pageNumber,
        string $text,
        float $advance,
    ): TextRun {
        $toDevice = $state->textMatrix->multiply($ctm);

        $top = $state->rise + $font->ascent * $state->fontSize;
        $bottom = $state->rise - $font->descent * $state->fontSize;

        $corners = [
            $toDevice->apply(0.0, $bottom),
            $toDevice->apply($advance, $bottom),
            $toDevice->apply($advance, $top),
            $toDevice->apply(0.0, $top),
        ];

        $natives = array_map(
            static fn (array $point): array => $transform->toNative(new UserSpacePoint($point[0], $point[1]))->toArray(),
            $corners,
        );

        $xs = array_column($natives, 0);
        $ys = array_column($natives, 1);

        // Direction is measured in native space, after the display rotation, so a page
        // with /Rotate 90 correctly reports its horizontal text as running top to bottom.
        $baselineStart = $transform->toNative(new UserSpacePoint(...$toDevice->apply(0.0, $state->rise)));
        $baselineEnd = $transform->toNative(new UserSpacePoint(...$toDevice->apply($advance, $state->rise)));

        return new TextRun(
            $pageNumber,
            $text,
            new NativeRect(min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)),
            $state->fontSize * $toDevice->scaleY(),
            $state->fontResource,
            TextDirection::fromAdvance($baselineEnd->x - $baselineStart->x, $baselineEnd->y - $baselineStart->y),
        );
    }

    /**
     * @param  array<string, array<int, mixed>>  $resources
     * @return array<string, PdfFontModel>
     */
    private function loadFonts(PdfObjectGraph $graph, array $resources): array
    {
        $fontResources = $graph->dictEntryAsDictionary($resources, 'Font') ?? [];
        $reader = new FontDictionaryReader($graph);

        $fonts = [];
        foreach (array_keys($fontResources) as $name) {
            $dict = $graph->dictEntryAsDictionary($fontResources, $name);
            if ($dict !== null) {
                $fonts[$name] = $reader->read($name, $dict);
            }
        }

        return $fonts;
    }
}
