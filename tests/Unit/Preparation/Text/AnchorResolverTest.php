<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Text;

use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Text\AmbiguousAnchorException;
use App\Domain\Preparation\Text\Anchor;
use App\Domain\Preparation\Text\AnchorNotFoundException;
use App\Domain\Preparation\Text\AnchorOccurrence;
use App\Domain\Preparation\Text\AnchorOrigin;
use App\Domain\Preparation\Text\AnchorResolver;
use App\Domain\Preparation\Text\OccurrenceOutOfRangeException;
use App\Domain\Preparation\Text\TextDirection;
use App\Domain\Preparation\Text\TextRun;
use PHPUnit\Framework\TestCase;

/**
 * Anchor semantics on synthetic runs. Extraction quality is covered separately by the
 * fixture-matrix tests; here the runs are handed in directly so the policy is isolated.
 */
final class AnchorResolverTest extends TestCase
{
    /** Ten characters wide at 7.2 pt each, matching the monospaced fixtures. */
    private static function textRun(int $page, string $text, float $x, float $y, TextDirection $direction = TextDirection::LeftToRight): TextRun
    {
        $length = (float) mb_strlen($text, 'UTF-8');
        $rect = $direction->isVertical()
            ? new NativeRect($x, $y, 12.0, $length * 7.2)
            : new NativeRect($x, $y, $length * 7.2, 12.0);

        return new TextRun($page, $text, $rect, 12.0, 'F1', $direction);
    }

    /** @return array<int, TextRun> */
    private static function threeSignatures(): array
    {
        return [
            self::textRun(1, 'Signature:', 72.0, 100.0),
            self::textRun(1, 'Signature:', 72.0, 200.0),
            self::textRun(2, 'Signature:', 72.0, 300.0),
        ];
    }

    public function test_a_sole_anchor_resolves_when_the_string_occurs_once(): void
    {
        $runs = [self::textRun(1, 'Signature:', 72.0, 100.0)];
        $anchor = new Anchor('Signature:', AnchorOccurrence::sole(), offsetY: 20.0, width: 170.0, height: 36.0);

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        $this->assertCount(1, $resolved);
        $this->assertSame(1, $resolved[0]->page);
        $this->assertSame(1, $resolved[0]->occurrenceIndex);
        $this->assertSame([72.0, 100.0, 72.0, 12.0], $resolved[0]->anchorRect->toArray());
        $this->assertSame([72.0, 120.0, 170.0, 36.0], $resolved[0]->resolvedRect->toArray());
    }

    public function test_a_missing_required_anchor_throws(): void
    {
        $anchor = new Anchor('Countersignature:', AnchorOccurrence::sole());

        $this->expectException(AnchorNotFoundException::class);
        $this->expectExceptionMessage('was not found');

        (new AnchorResolver)->resolve(self::threeSignatures(), $anchor);
    }

    public function test_a_missing_optional_anchor_resolves_to_nothing(): void
    {
        $anchor = new Anchor('Countersignature:', AnchorOccurrence::sole(), required: false);

        $this->assertSame([], (new AnchorResolver)->resolve(self::threeSignatures(), $anchor));
    }

    public function test_an_ambiguous_sole_anchor_throws_instead_of_taking_the_first_match(): void
    {
        $anchor = new Anchor('Signature:', AnchorOccurrence::sole());

        $this->expectException(AmbiguousAnchorException::class);
        $this->expectExceptionMessage('matched 3 times');

        (new AnchorResolver)->resolve(self::threeSignatures(), $anchor);
    }

    public function test_scoping_an_anchor_to_a_page_narrows_the_match_set(): void
    {
        $anchor = new Anchor('Signature:', AnchorOccurrence::sole(), page: 2);

        $resolved = (new AnchorResolver)->resolve(self::threeSignatures(), $anchor);

        $this->assertCount(1, $resolved);
        $this->assertSame(2, $resolved[0]->page);
        $this->assertSame(300.0, $resolved[0]->anchorRect->y);
    }

    public function test_an_occurrence_index_selects_a_specific_match_in_document_order(): void
    {
        $resolver = new AnchorResolver;

        $first = $resolver->resolve(self::threeSignatures(), new Anchor('Signature:', AnchorOccurrence::index(1)));
        $second = $resolver->resolve(self::threeSignatures(), new Anchor('Signature:', AnchorOccurrence::index(2)));
        $third = $resolver->resolve(self::threeSignatures(), new Anchor('Signature:', AnchorOccurrence::index(3)));

        $this->assertSame(100.0, $first[0]->anchorRect->y);
        $this->assertSame(200.0, $second[0]->anchorRect->y);
        $this->assertSame(2, $third[0]->page);
    }

    public function test_an_occurrence_index_beyond_the_match_count_throws(): void
    {
        $this->expectException(OccurrenceOutOfRangeException::class);
        $this->expectExceptionMessage('only 3 matches were found');

        (new AnchorResolver)->resolve(self::threeSignatures(), new Anchor('Signature:', AnchorOccurrence::index(4)));
    }

    public function test_all_occurrences_resolve_to_one_rectangle_each(): void
    {
        $anchor = new Anchor('Signature:', AnchorOccurrence::all(), offsetY: 14.0, width: 100.0, height: 20.0);

        $resolved = (new AnchorResolver)->resolve(self::threeSignatures(), $anchor);

        $this->assertCount(3, $resolved);
        $this->assertSame([1, 2, 3], array_map(static fn ($r): int => $r->occurrenceIndex, $resolved));
        $this->assertSame([114.0, 214.0, 314.0], array_map(static fn ($r): float => $r->resolvedRect->y, $resolved));
    }

    public function test_resolution_is_stable_across_runs(): void
    {
        $resolver = new AnchorResolver;
        $anchor = new Anchor('Signature:', AnchorOccurrence::all(), width: 10.0, height: 10.0);

        $first = array_map(static fn ($r): array => $r->toArray(), $resolver->resolve(self::threeSignatures(), $anchor));
        $second = array_map(static fn ($r): array => $r->toArray(), $resolver->resolve(self::threeSignatures(), $anchor));

        $this->assertSame($first, $second);
    }

    public function test_matching_is_exact_and_case_sensitive(): void
    {
        $runs = [self::textRun(1, 'Signature:', 72.0, 100.0)];

        $this->expectException(AnchorNotFoundException::class);

        (new AnchorResolver)->resolve($runs, new Anchor('signature:', AnchorOccurrence::sole()));
    }

    public function test_a_substring_match_narrows_the_rectangle_to_the_matched_characters(): void
    {
        // "Countersignature:" is 17 characters; "signature:" starts at index 7.
        $runs = [self::textRun(1, 'Countersignature:', 72.0, 100.0)];
        $anchor = new Anchor('signature:', AnchorOccurrence::sole());

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        $this->assertEqualsWithDelta(72.0 + 7 * 7.2, $resolved[0]->anchorRect->x, 1.0e-9);
        $this->assertEqualsWithDelta(10 * 7.2, $resolved[0]->anchorRect->width, 1.0e-9);
    }

    public function test_two_matches_inside_one_run_are_both_found(): void
    {
        $runs = [self::textRun(1, 'Sign here and sign here', 72.0, 100.0)];
        $anchor = new Anchor('here', AnchorOccurrence::all());

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        $this->assertCount(2, $resolved);
        $this->assertEqualsWithDelta(72.0 + 5 * 7.2, $resolved[0]->anchorRect->x, 1.0e-9);
        $this->assertEqualsWithDelta(72.0 + 19 * 7.2, $resolved[1]->anchorRect->x, 1.0e-9);
    }

    public function test_a_vertical_run_is_sliced_along_the_native_y_axis(): void
    {
        $runs = [self::textRun(1, 'Countersignature:', 500.0, 100.0, TextDirection::TopToBottom)];
        $anchor = new Anchor('signature:', AnchorOccurrence::sole());

        $resolved = (new AnchorResolver)->resolve($runs, $anchor);

        $this->assertEqualsWithDelta(500.0, $resolved[0]->anchorRect->x, 1.0e-9);
        $this->assertEqualsWithDelta(100.0 + 7 * 7.2, $resolved[0]->anchorRect->y, 1.0e-9);
        $this->assertEqualsWithDelta(10 * 7.2, $resolved[0]->anchorRect->height, 1.0e-9);
    }

    public function test_an_off_axis_run_keeps_its_whole_bounding_box(): void
    {
        $runs = [new TextRun(1, 'Countersignature:', new NativeRect(100.0, 100.0, 80.0, 80.0), 12.0, 'F1', TextDirection::Other)];

        $resolved = (new AnchorResolver)->resolve($runs, new Anchor('signature:', AnchorOccurrence::sole()));

        $this->assertSame([100.0, 100.0, 80.0, 80.0], $resolved[0]->anchorRect->toArray());
    }

    public function test_the_offset_origin_corner_is_declared_not_inferred(): void
    {
        $runs = [self::textRun(1, 'Signature:', 72.0, 100.0)];

        $topLeft = (new AnchorResolver)->resolve($runs, new Anchor(
            'Signature:', AnchorOccurrence::sole(), offsetX: 5.0, offsetY: 5.0, origin: AnchorOrigin::TopLeft,
        ));
        $bottomRight = (new AnchorResolver)->resolve($runs, new Anchor(
            'Signature:', AnchorOccurrence::sole(), offsetX: 5.0, offsetY: 5.0, origin: AnchorOrigin::BottomRight,
        ));

        $this->assertSame([77.0, 105.0], [$topLeft[0]->resolvedRect->x, $topLeft[0]->resolvedRect->y]);
        $this->assertSame([149.0, 117.0], [$bottomRight[0]->resolvedRect->x, $bottomRight[0]->resolvedRect->y]);
    }

    public function test_an_empty_anchor_string_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Anchor('', AnchorOccurrence::sole());
    }
}
