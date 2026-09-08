<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Geometry;

use App\Domain\Preparation\Geometry\CoordinateTransform;
use App\Domain\Preparation\Geometry\NativePoint;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Geometry\PageBox;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Preparation\Geometry\UserSpacePoint;
use App\Domain\Preparation\Geometry\UserSpaceRect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every expectation in this file is computed by hand from the definition in
 * docs/preparation/coordinate-space.md, not by running the implementation. That is the
 * point: the fixture generator has its own copy of the same arithmetic, so if both were
 * derived from the code a shared sign error would pass unnoticed.
 */
final class CoordinateTransformTest extends TestCase
{
    private const EPSILON = 1.0e-9;

    private static function letter(PageRotation $rotation, float $userUnit = 1.0): CoordinateTransform
    {
        $box = new PageBox(0.0, 0.0, 612.0, 792.0);

        return new CoordinateTransform(new PageGeometry(1, $box, $box, $rotation, $userUnit));
    }

    /** MediaBox 612x792 with CropBox [36 48 576 744]: 540 wide, 696 high, origin (36, 48). */
    private static function offsetCrop(PageRotation $rotation): CoordinateTransform
    {
        return new CoordinateTransform(new PageGeometry(
            1,
            new PageBox(0.0, 0.0, 612.0, 792.0),
            new PageBox(36.0, 48.0, 576.0, 744.0),
            $rotation,
        ));
    }

    /**
     * @return array<string, array{PageRotation, float, float, float, float}>
     */
    public static function unrotatedCorners(): array
    {
        // Rotation 0: native (x, y) -> user (x, 792 - y) on a 612x792 CropBox at the origin.
        return [
            'top-left corner' => [PageRotation::None, 0.0, 0.0, 0.0, 792.0],
            'top-right corner' => [PageRotation::None, 612.0, 0.0, 612.0, 792.0],
            'bottom-left corner' => [PageRotation::None, 0.0, 792.0, 0.0, 0.0],
            'bottom-right corner' => [PageRotation::None, 612.0, 792.0, 612.0, 0.0],
            'interior point' => [PageRotation::None, 100.0, 50.0, 100.0, 742.0],
        ];
    }

    #[DataProvider('unrotatedCorners')]
    public function test_unrotated_page_maps_native_to_user_space(
        PageRotation $rotation,
        float $nativeX,
        float $nativeY,
        float $userX,
        float $userY,
    ): void {
        $point = self::letter($rotation)->toUserSpace(new NativePoint($nativeX, $nativeY));

        $this->assertEqualsWithDelta($userX, $point->x, self::EPSILON);
        $this->assertEqualsWithDelta($userY, $point->y, self::EPSILON);
    }

    /**
     * Rotating the page 90 degrees clockwise for display sends the stored bottom-left
     * corner to the displayed top-left corner, so native (0, 0) is user (0, 0).
     */
    public function test_rotation_90_maps_native_origin_to_the_stored_bottom_left_corner(): void
    {
        $transform = self::letter(PageRotation::Clockwise90);

        $this->assertSame(792.0, $transform->page->nativeWidth());
        $this->assertSame(612.0, $transform->page->nativeHeight());

        $origin = $transform->toUserSpace(new NativePoint(0.0, 0.0));
        $this->assertEqualsWithDelta(0.0, $origin->x, self::EPSILON);
        $this->assertEqualsWithDelta(0.0, $origin->y, self::EPSILON);

        // Displayed top-right is the stored top-left corner.
        $topRight = $transform->toUserSpace(new NativePoint(792.0, 0.0));
        $this->assertEqualsWithDelta(0.0, $topRight->x, self::EPSILON);
        $this->assertEqualsWithDelta(792.0, $topRight->y, self::EPSILON);

        // Displayed bottom-left is the stored bottom-right corner.
        $bottomLeft = $transform->toUserSpace(new NativePoint(0.0, 612.0));
        $this->assertEqualsWithDelta(612.0, $bottomLeft->x, self::EPSILON);
        $this->assertEqualsWithDelta(0.0, $bottomLeft->y, self::EPSILON);

        // native (100, 50) -> px = 50, py = 792 - 100 = 692 -> user (50, 792 - 692) = (50, 100)
        $interior = $transform->toUserSpace(new NativePoint(100.0, 50.0));
        $this->assertEqualsWithDelta(50.0, $interior->x, self::EPSILON);
        $this->assertEqualsWithDelta(100.0, $interior->y, self::EPSILON);
    }

    public function test_rotation_180_maps_native_origin_to_the_stored_bottom_right_corner(): void
    {
        $transform = self::letter(PageRotation::Clockwise180);

        $this->assertSame(612.0, $transform->page->nativeWidth());
        $this->assertSame(792.0, $transform->page->nativeHeight());

        $origin = $transform->toUserSpace(new NativePoint(0.0, 0.0));
        $this->assertEqualsWithDelta(612.0, $origin->x, self::EPSILON);
        $this->assertEqualsWithDelta(0.0, $origin->y, self::EPSILON);

        // native (100, 50) -> px = 512, py = 742 -> user (512, 792 - 742) = (512, 50)
        $interior = $transform->toUserSpace(new NativePoint(100.0, 50.0));
        $this->assertEqualsWithDelta(512.0, $interior->x, self::EPSILON);
        $this->assertEqualsWithDelta(50.0, $interior->y, self::EPSILON);
    }

    public function test_rotation_270_maps_native_origin_to_the_stored_top_right_corner(): void
    {
        $transform = self::letter(PageRotation::Clockwise270);

        $this->assertSame(792.0, $transform->page->nativeWidth());
        $this->assertSame(612.0, $transform->page->nativeHeight());

        $origin = $transform->toUserSpace(new NativePoint(0.0, 0.0));
        $this->assertEqualsWithDelta(612.0, $origin->x, self::EPSILON);
        $this->assertEqualsWithDelta(792.0, $origin->y, self::EPSILON);

        // native (100, 50) -> px = 612 - 50 = 562, py = 100 -> user (562, 792 - 100) = (562, 692)
        $interior = $transform->toUserSpace(new NativePoint(100.0, 50.0));
        $this->assertEqualsWithDelta(562.0, $interior->x, self::EPSILON);
        $this->assertEqualsWithDelta(692.0, $interior->y, self::EPSILON);
    }

    public function test_nonzero_crop_box_origin_shifts_the_native_origin(): void
    {
        $transform = self::offsetCrop(PageRotation::None);

        $this->assertSame(540.0, $transform->page->nativeWidth());
        $this->assertSame(696.0, $transform->page->nativeHeight());

        // native (0, 0) is the top-left of the CropBox, i.e. user (36, 48 + 696) = (36, 744)
        $origin = $transform->toUserSpace(new NativePoint(0.0, 0.0));
        $this->assertEqualsWithDelta(36.0, $origin->x, self::EPSILON);
        $this->assertEqualsWithDelta(744.0, $origin->y, self::EPSILON);

        // native (80, 300) -> user (36 + 80, 48 + 696 - 300) = (116, 444)
        $interior = $transform->toUserSpace(new NativePoint(80.0, 300.0));
        $this->assertEqualsWithDelta(116.0, $interior->x, self::EPSILON);
        $this->assertEqualsWithDelta(444.0, $interior->y, self::EPSILON);

        // The opposite native corner is the top-right of the CropBox in user space.
        $far = $transform->toUserSpace(new NativePoint(540.0, 696.0));
        $this->assertEqualsWithDelta(576.0, $far->x, self::EPSILON);
        $this->assertEqualsWithDelta(48.0, $far->y, self::EPSILON);
    }

    public function test_nonzero_crop_box_origin_combined_with_rotation_90(): void
    {
        $transform = self::offsetCrop(PageRotation::Clockwise90);

        $this->assertSame(696.0, $transform->page->nativeWidth());
        $this->assertSame(540.0, $transform->page->nativeHeight());

        // native (0, 0) -> px = 0, py = 696 -> user (36, 48)
        $origin = $transform->toUserSpace(new NativePoint(0.0, 0.0));
        $this->assertEqualsWithDelta(36.0, $origin->x, self::EPSILON);
        $this->assertEqualsWithDelta(48.0, $origin->y, self::EPSILON);

        // native (200, 120) -> px = 120, py = 696 - 200 = 496 -> user (156, 48 + 696 - 496) = (156, 248)
        $interior = $transform->toUserSpace(new NativePoint(200.0, 120.0));
        $this->assertEqualsWithDelta(156.0, $interior->x, self::EPSILON);
        $this->assertEqualsWithDelta(248.0, $interior->y, self::EPSILON);
    }

    public function test_rectangles_map_to_user_space_and_back(): void
    {
        $transform = self::letter(PageRotation::None);
        $rect = new NativeRect(100.0, 300.0, 170.0, 36.0);

        $userRect = $transform->rectToUserSpace($rect);
        $this->assertTrue(
            $userRect->equals(new UserSpaceRect(100.0, 456.0, 270.0, 492.0)),
            'Expected [100 456 270 492] in user space, got ['.implode(' ', $userRect->toArray()).'].',
        );

        $this->assertTrue($transform->rectToNative($userRect)->equals($rect));
    }

    public function test_a_rotated_rectangle_keeps_its_area_and_swaps_its_axes(): void
    {
        $transform = self::letter(PageRotation::Clockwise90);
        $rect = new NativeRect(100.0, 50.0, 170.0, 36.0);

        $userRect = $transform->rectToUserSpace($rect);

        // native x runs down user y, native y runs along user x, so the box transposes.
        $this->assertEqualsWithDelta(36.0, $userRect->width(), self::EPSILON);
        $this->assertEqualsWithDelta(170.0, $userRect->height(), self::EPSILON);
        $this->assertTrue($transform->rectToNative($userRect)->equals($rect));
    }

    /**
     * @return \Generator<string, array{PageRotation}>
     */
    public static function everyRotation(): \Generator
    {
        foreach (PageRotation::cases() as $rotation) {
            yield 'rotate '.$rotation->value => [$rotation];
        }
    }

    #[DataProvider('everyRotation')]
    public function test_round_trip_is_lossless_for_every_rotation(PageRotation $rotation): void
    {
        foreach ([self::letter($rotation), self::offsetCrop($rotation)] as $transform) {
            foreach ([[0.0, 0.0], [1.5, 2.5], [123.25, 456.75], [317.0, 88.125]] as [$x, $y]) {
                $native = new NativePoint($x, $y);
                $roundTripped = $transform->toNative($transform->toUserSpace($native));

                $this->assertEqualsWithDelta($native->x, $roundTripped->x, self::EPSILON);
                $this->assertEqualsWithDelta($native->y, $roundTripped->y, self::EPSILON);
            }
        }
    }

    #[DataProvider('everyRotation')]
    public function test_the_page_corners_map_onto_the_crop_box_corners(PageRotation $rotation): void
    {
        $transform = self::offsetCrop($rotation);
        $page = $transform->page;

        $corners = [
            $transform->toUserSpace(new NativePoint(0.0, 0.0)),
            $transform->toUserSpace(new NativePoint($page->nativeWidth(), 0.0)),
            $transform->toUserSpace(new NativePoint(0.0, $page->nativeHeight())),
            $transform->toUserSpace(new NativePoint($page->nativeWidth(), $page->nativeHeight())),
        ];

        $xs = array_map(static fn (UserSpacePoint $p): float => $p->x, $corners);
        $ys = array_map(static fn (UserSpacePoint $p): float => $p->y, $corners);

        $this->assertEqualsWithDelta(36.0, min($xs), self::EPSILON);
        $this->assertEqualsWithDelta(576.0, max($xs), self::EPSILON);
        $this->assertEqualsWithDelta(48.0, min($ys), self::EPSILON);
        $this->assertEqualsWithDelta(744.0, max($ys), self::EPSILON);
    }

    public function test_user_unit_does_not_change_the_native_to_user_space_transform(): void
    {
        $plain = self::letter(PageRotation::None, 1.0);
        $scaled = self::letter(PageRotation::None, 2.0);

        $point = new NativePoint(100.0, 50.0);

        $this->assertEquals(
            $plain->toUserSpace($point)->toArray(),
            $scaled->toUserSpace($point)->toArray(),
            'Native coordinates are default user-space units; /UserUnit must not rescale them.',
        );

        $this->assertSame(612.0, $scaled->page->nativeWidth());
        $this->assertSame(792.0, $scaled->page->nativeHeight());
    }

    public function test_user_unit_is_applied_only_through_the_declared_physical_conversion(): void
    {
        $transform = self::letter(PageRotation::None, 2.0);

        $this->assertSame(1224.0, $transform->page->physicalWidthPt());
        $this->assertSame(1584.0, $transform->page->physicalHeightPt());
        $this->assertSame(200.0, $transform->nativeToPhysicalPoints(100.0));
        $this->assertSame(100.0, $transform->physicalPointsToNative(200.0));

        $physical = $transform->rectToPhysicalPoints(new NativeRect(10.0, 20.0, 30.0, 40.0));
        $this->assertSame([20.0, 40.0, 60.0, 80.0], $physical->toArray());
    }

    public function test_containment_uses_the_displayed_page_size(): void
    {
        $transform = self::letter(PageRotation::Clockwise90);

        $this->assertTrue($transform->containsRect(new NativeRect(700.0, 500.0, 90.0, 100.0)));
        $this->assertFalse($transform->containsRect(new NativeRect(700.0, 500.0, 90.0, 200.0)));
        $this->assertFalse($transform->containsRect(new NativeRect(-1.0, 0.0, 10.0, 10.0)));
    }
}
