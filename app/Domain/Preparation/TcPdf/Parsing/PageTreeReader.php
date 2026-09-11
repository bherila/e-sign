<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use App\Domain\Preparation\Geometry\InvalidGeometryException;
use App\Domain\Preparation\Geometry\PageBox;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;

/**
 * Walks the /Pages tree and produces one flattened, geometry-resolved page per leaf.
 *
 * Inheritable attributes (/Resources, /MediaBox, /CropBox, /Rotate) are resolved down
 * the tree as ISO 32000-1 7.7.3.4 requires. The declared /Count is ignored: only pages
 * actually reachable through /Kids are returned, so a forged count cannot inflate the
 * page total. Cyclic and repeated nodes abort the walk.
 */
final readonly class PageTreeReader
{
    private const INHERITABLE = ['Resources', 'MediaBox', 'CropBox', 'Rotate'];

    public function __construct(private PdfObjectGraph $graph) {}

    /**
     * @param  PreflightBudget  $budget  The document's. Its page ceiling is the one walked to, and a
     *                                   tree that goes past it is refused by the budget, as every
     *                                   other ceiling is — not as a malformed tree.
     * @return array<int, FlattenedPage>
     *
     * @throws MalformedPageTreeException When the tree does not describe pages.
     * @throws PreflightBudgetException When it describes more pages than the ceiling allows.
     */
    public function pages(PreflightBudget $budget): array
    {
        $rootRef = $this->graph->rootRef();
        if ($rootRef === null) {
            throw new MalformedPageTreeException('The trailer does not reference a /Root catalog object.');
        }

        $catalog = $this->graph->dictionary($rootRef);
        if ($catalog === null) {
            throw new MalformedPageTreeException('The /Root object is not a dictionary.');
        }

        $pagesRef = $this->graph->dictEntryRef($catalog, 'Pages');
        $pagesDict = $pagesRef !== null
            ? $this->graph->dictionary($pagesRef)
            : $this->graph->dictEntryAsDictionary($catalog, 'Pages');

        if ($pagesDict === null) {
            throw new MalformedPageTreeException('The catalog does not reference a /Pages tree.');
        }

        $pages = [];
        $seen = [];
        $this->walk($pagesRef ?? '', $pagesDict, [], $pages, $seen, $budget, 0);

        return $pages;
    }

    /**
     * @param  array<string, array<int, mixed>>  $node
     * @param  array<string, array<int, mixed>>  $inherited
     * @param  array<int, FlattenedPage>  $pages
     * @param  array<string, bool>  $seen
     */
    private function walk(
        string $ref,
        array $node,
        array $inherited,
        array &$pages,
        array &$seen,
        PreflightBudget $budget,
        int $depth,
    ): void {
        if ($depth > 64) {
            throw new MalformedPageTreeException('The page tree is nested more than 64 levels deep.');
        }

        if ($ref !== '') {
            if (isset($seen[$ref])) {
                throw new MalformedPageTreeException('The page tree revisits object '.$ref.'.');
            }
            $seen[$ref] = true;
        }

        foreach (self::INHERITABLE as $key) {
            if (array_key_exists($key, $node)) {
                $inherited[$key] = $node[$key];
            }
        }

        $type = $this->graph->dictEntryAsName($node, 'Type');
        $kids = $this->graph->dictEntryAsArray($node, 'Kids');

        if ($type === 'Page' || ($kids === null && $type !== 'Pages')) {
            if (count($pages) >= $budget->limits->maxPages) {
                $budget->exhaustPages();
            }

            $pages[] = $this->flatten(count($pages) + 1, $node, $inherited);

            return;
        }

        if ($kids === null) {
            throw new MalformedPageTreeException('A /Pages node has no /Kids array.');
        }

        foreach ($kids as $kid) {
            if (($kid[0] ?? null) !== 'objref' || ! is_string($kid[1] ?? null)) {
                throw new MalformedPageTreeException('A /Kids entry is not an indirect reference.');
            }

            $kidRef = $kid[1];
            $kidDict = $this->graph->dictionary($kidRef);
            if ($kidDict === null) {
                throw new MalformedPageTreeException('The /Kids entry '.$kidRef.' is not a dictionary.');
            }

            $this->walk($kidRef, $kidDict, $inherited, $pages, $seen, $budget, $depth + 1);
        }
    }

    /**
     * @param  array<string, array<int, mixed>>  $node
     * @param  array<string, array<int, mixed>>  $inherited
     */
    private function flatten(int $pageNumber, array $node, array $inherited): FlattenedPage
    {
        $effective = $node + $inherited;

        $media = $this->box($effective, 'MediaBox');
        if (! $media instanceof PageBox) {
            // ISO 32000-1 makes /MediaBox required and inheritable, but a missing box is
            // common enough in the wild that defaulting to US Letter is safer than failing.
            $media = new PageBox(0.0, 0.0, 612.0, 792.0);
        }

        $crop = $this->box($effective, 'CropBox');
        $rotate = $this->graph->dictEntryAsInt($effective, 'Rotate') ?? 0;
        $userUnit = $this->graph->dictEntryAsFloat($effective, 'UserUnit') ?? 1.0;

        try {
            $geometry = PageGeometry::create(
                $pageNumber,
                $media,
                $crop,
                PageRotation::fromDegrees($rotate),
                $userUnit,
            );
        } catch (InvalidGeometryException $exception) {
            throw new MalformedPageTreeException(
                'Page '.$pageNumber.' has unusable geometry: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return new FlattenedPage(
            $geometry,
            $node,
            $this->graph->dictEntryAsDictionary($effective, 'Resources') ?? [],
            $this->graph->dictEntryAsArray($node, 'Annots') ?? [],
        );
    }

    /**
     * @param  array<string, array<int, mixed>>  $dict
     */
    private function box(array $dict, string $key): ?PageBox
    {
        $numbers = $this->graph->dictEntryAsNumbers($dict, $key);
        if ($numbers === null || count($numbers) !== 4) {
            return null;
        }

        try {
            return PageBox::fromArray($numbers);
        } catch (InvalidGeometryException) {
            return null;
        }
    }
}
