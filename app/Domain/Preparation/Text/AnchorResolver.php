<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use App\Domain\Preparation\Geometry\NativeRect;

/**
 * Anchor semantics, independent of any PDF library.
 *
 * Given positioned text runs and an anchor, produce the rectangles to store. The
 * resolver owns exactly one policy decision: what counts as a match and which match
 * wins. Extraction quality is the locator's problem; ordering and error behaviour
 * are this class's problem.
 *
 * Match ordering is deterministic: by page, then by the top edge of the run, then by
 * its left edge, then by the character offset within the run. Two runs at the same
 * position keep the order the content stream drew them in.
 */
final readonly class AnchorResolver
{
    /**
     * @param  array<int, TextRun>  $runs
     * @return array<int, ResolvedAnchor>
     *
     * @throws AnchorNotFoundException
     * @throws AmbiguousAnchorException
     * @throws OccurrenceOutOfRangeException
     */
    public function resolve(array $runs, Anchor $anchor): array
    {
        $matches = $this->matches($runs, $anchor);
        $count = count($matches);

        if ($count === 0) {
            if ($anchor->required) {
                throw AnchorNotFoundException::for($anchor);
            }

            return [];
        }

        if ($anchor->occurrence->isAll()) {
            return array_map(
                fn (array $match, int $i): ResolvedAnchor => $this->build($anchor, $match, $i + 1),
                $matches,
                array_keys($matches),
            );
        }

        if ($anchor->occurrence->isSole()) {
            if ($count > 1) {
                throw AmbiguousAnchorException::for($anchor, $count);
            }

            return [$this->build($anchor, $matches[0], 1)];
        }

        $index = (int) $anchor->occurrence->index;
        if ($index > $count) {
            throw OccurrenceOutOfRangeException::for($anchor, $count);
        }

        return [$this->build($anchor, $matches[$index - 1], $index)];
    }

    /**
     * Every occurrence of the anchor string, in deterministic document order.
     *
     * @param  array<int, TextRun>  $runs
     * @return array<int, array{run: TextRun, offset: int}>
     */
    public function matches(array $runs, Anchor $anchor): array
    {
        $candidates = array_values(array_filter(
            $runs,
            static fn (TextRun $run): bool => $anchor->page === null || $run->page === $anchor->page,
        ));

        usort($candidates, static function (TextRun $a, TextRun $b): int {
            return [$a->page, round($a->rect->y, 4), round($a->rect->x, 4)]
                <=> [$b->page, round($b->rect->y, 4), round($b->rect->x, 4)];
        });

        $matches = [];
        foreach ($candidates as $run) {
            $offset = 0;
            while (($position = strpos($run->text, $anchor->text, $offset)) !== false) {
                $matches[] = ['run' => $run, 'offset' => $position];
                $offset = $position + 1;
            }
        }

        return $matches;
    }

    /** @param array{run: TextRun, offset: int} $match */
    private function build(Anchor $anchor, array $match, int $occurrenceIndex): ResolvedAnchor
    {
        $run = $match['run'];
        $anchorRect = $this->matchRect($run, $anchor->text, $match['offset']);

        [$originX, $originY] = match ($anchor->origin) {
            AnchorOrigin::TopLeft => [$anchorRect->x, $anchorRect->y],
            AnchorOrigin::TopRight => [$anchorRect->right(), $anchorRect->y],
            AnchorOrigin::BottomLeft => [$anchorRect->x, $anchorRect->bottom()],
            AnchorOrigin::BottomRight => [$anchorRect->right(), $anchorRect->bottom()],
        };

        return new ResolvedAnchor(
            $anchor,
            $run->page,
            $occurrenceIndex,
            $anchorRect,
            new NativeRect(
                $originX + $anchor->offsetX,
                $originY + $anchor->offsetY,
                $anchor->width,
                $anchor->height,
            ),
            $anchor->text,
        );
    }

    /**
     * Narrow a run's rectangle down to the matched substring.
     *
     * The run box is divided evenly across its characters along the run's advance
     * direction, which is measured in native space so a page with /Rotate 90 slices
     * vertically. That division is exact for the monospaced and Identity-H fixtures used
     * in Stage 0 and an approximation for proportional fonts; a run whose direction is
     * `Other` (rotated off-axis) keeps its whole bounding box, because slicing an
     * axis-aligned box along a diagonal baseline would be wrong in a way that looks right.
     */
    private function matchRect(TextRun $run, string $needle, int $offset): NativeRect
    {
        $runLength = mb_strlen($run->text, 'UTF-8');
        if ($run->direction === TextDirection::Other || $runLength === 0) {
            return $run->rect;
        }

        $charsBefore = mb_strlen(substr($run->text, 0, $offset), 'UTF-8');
        $charsMatched = mb_strlen($needle, 'UTF-8');
        if ($run->direction->isReversed()) {
            $charsBefore = $runLength - $charsBefore - $charsMatched;
        }

        if ($run->direction->isHorizontal()) {
            $perChar = $run->rect->width / $runLength;

            return new NativeRect(
                $run->rect->x + $charsBefore * $perChar,
                $run->rect->y,
                $charsMatched * $perChar,
                $run->rect->height,
            );
        }

        $perChar = $run->rect->height / $runLength;

        return new NativeRect(
            $run->rect->x,
            $run->rect->y + $charsBefore * $perChar,
            $run->rect->width,
            $charsMatched * $perChar,
        );
    }
}
