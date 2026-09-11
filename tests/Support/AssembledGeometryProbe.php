<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Preparation\Geometry\CoordinateTransform;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Geometry\UserSpacePoint;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\TcPdf\Parsing\ContentStreamTokenizer;
use App\Domain\Preparation\TcPdf\Parsing\FlattenedPage;
use App\Domain\Preparation\TcPdf\Parsing\Matrix;
use App\Domain\Preparation\TcPdf\Parsing\PageTreeReader;
use App\Domain\Preparation\TcPdf\Parsing\PdfObjectGraph;

/**
 * Reads rectangles back out of an assembled PDF, in native coordinates.
 *
 * No rasteriser is involved. The probe composes the same matrices a viewer would:
 * the rectangle's coordinates in whatever space it was drawn in, then the form
 * XObject's /Matrix, then the placement CTM, then the page's native transform. That
 * is enough to answer the only question the fixture matrix asks — did the mark end up
 * where the coordinate space says it should — without a Chromium or poppler dependency.
 */
final readonly class AssembledGeometryProbe
{
    private PdfObjectGraph $graph;

    /** @var array<int, FlattenedPage> */
    private array $pages;

    public function __construct(string $pdfBytes)
    {
        $budget = new PreflightBudget;
        $this->graph = PdfObjectGraph::parse($pdfBytes, $budget);
        $this->pages = (new PageTreeReader($this->graph))->pages($budget);
    }

    /**
     * Rectangles painted directly on the page (the assembler's overlays), in native units.
     *
     * @return array<int, NativeRect>
     */
    public function overlayRects(int $pageNumber): array
    {
        $page = $this->page($pageNumber);
        $transform = new CoordinateTransform($page->geometry);

        return array_map(
            fn (array $corners): NativeRect => $this->toNativeRect($transform, $corners),
            $this->rectanglesIn($this->graph->contentStream($page->dictionary), Matrix::identity(), ['S', 's']),
        );
    }

    /**
     * Rectangles filled inside the imported page templates placed on this page, in native units.
     *
     * @return array<int, NativeRect>
     */
    public function importedFillRects(int $pageNumber): array
    {
        $page = $this->page($pageNumber);
        $transform = new CoordinateTransform($page->geometry);
        $xobjects = $this->graph->dictEntryAsDictionary($page->resources, 'XObject') ?? [];

        $out = [];
        foreach ($this->placements($this->graph->contentStream($page->dictionary)) as [$name, $placement]) {
            $ref = $this->graph->dictEntryRef($xobjects, $name);
            if ($ref === null) {
                continue;
            }

            $formDict = $this->graph->dictionary($ref);
            $formData = $this->graph->streamData($ref);
            if ($formDict === null || $formData === null) {
                continue;
            }

            $matrixValues = $this->graph->dictEntryAsNumbers($formDict, 'Matrix') ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
            $toPage = Matrix::fromArray($matrixValues)->multiply($placement);

            foreach ($this->rectanglesIn($formData, $toPage, ['f', 'F', 'f*', 'b', 'b*', 'B', 'B*']) as $corners) {
                $out[] = $this->toNativeRect($transform, $corners);
            }
        }

        return $out;
    }

    /** The /Matrix of the form XObject placed on a page, for diagnostics. @return array<int, float>|null */
    public function firstFormMatrix(int $pageNumber): ?array
    {
        $page = $this->page($pageNumber);
        $xobjects = $this->graph->dictEntryAsDictionary($page->resources, 'XObject') ?? [];

        foreach ($this->placements($this->graph->contentStream($page->dictionary)) as [$name, $_placement]) {
            $ref = $this->graph->dictEntryRef($xobjects, $name);
            $formDict = $ref === null ? null : $this->graph->dictionary($ref);
            if ($formDict !== null) {
                return $this->graph->dictEntryAsNumbers($formDict, 'Matrix');
            }
        }

        return null;
    }

    private function page(int $pageNumber): FlattenedPage
    {
        foreach ($this->pages as $page) {
            if ($page->geometry->pageNumber === $pageNumber) {
                return $page;
            }
        }

        throw new \OutOfRangeException('The assembled document has no page '.$pageNumber.'.');
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * Every `/Name Do` in a content stream with the CTM in force at that point.
     *
     * @return array<int, array{string, Matrix}>
     */
    private function placements(string $content): array
    {
        $ctm = Matrix::identity();
        $stack = [];
        $out = [];

        foreach ((new ContentStreamTokenizer($content, new PreflightBudget))->operations() as $operation) {
            match ($operation->operator) {
                'q' => $stack[] = $ctm,
                'Q' => $ctm = array_pop($stack) ?? Matrix::identity(),
                'cm' => $ctm = ($values = $operation->trailingNumbers(6)) !== null
                    ? Matrix::fromArray($values)->multiply($ctm)
                    : $ctm,
                'Do' => ($name = $operation->name(count($operation->operands) - 1)) !== null
                    ? $out[] = [$name, $ctm]
                    : null,
                default => null,
            };
        }

        return $out;
    }

    /**
     * Every `re` painted with one of $paintOps, as four transformed user-space corners.
     *
     * @param  array<int, string>  $paintOps
     * @return array<int, array<int, array{float, float}>>
     */
    private function rectanglesIn(string $content, Matrix $base, array $paintOps): array
    {
        $ctm = $base;
        $stack = [];
        $pending = [];
        $out = [];

        foreach ((new ContentStreamTokenizer($content, new PreflightBudget))->operations() as $operation) {
            if ($operation->operator === 'q') {
                $stack[] = $ctm;

                continue;
            }

            if ($operation->operator === 'Q') {
                $ctm = array_pop($stack) ?? $base;

                continue;
            }

            if ($operation->operator === 'cm') {
                $values = $operation->trailingNumbers(6);
                if ($values !== null) {
                    $ctm = Matrix::fromArray($values)->multiply($ctm);
                }

                continue;
            }

            if ($operation->operator === 're') {
                $values = $operation->trailingNumbers(4);
                if ($values !== null) {
                    [$x, $y, $w, $h] = $values;
                    $pending[] = [
                        $ctm->apply($x, $y),
                        $ctm->apply($x + $w, $y),
                        $ctm->apply($x + $w, $y + $h),
                        $ctm->apply($x, $y + $h),
                    ];
                }

                continue;
            }

            if (in_array($operation->operator, $paintOps, true)) {
                foreach ($pending as $rect) {
                    $out[] = $rect;
                }
                $pending = [];

                continue;
            }

            if (in_array($operation->operator, ['n', 'W', 'W*'], true)) {
                continue;
            }

            // Any other painting operator discards the pending path for our purposes.
            if (in_array($operation->operator, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*'], true)) {
                $pending = [];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{float, float}>  $corners
     */
    private function toNativeRect(CoordinateTransform $transform, array $corners): NativeRect
    {
        $natives = array_map(
            static fn (array $point): array => $transform->toNative(new UserSpacePoint($point[0], $point[1]))->toArray(),
            $corners,
        );

        $xs = array_column($natives, 0);
        $ys = array_column($natives, 1);

        return new NativeRect(min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys));
    }
}
