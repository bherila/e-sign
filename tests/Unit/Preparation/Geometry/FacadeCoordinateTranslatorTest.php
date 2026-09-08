<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Geometry;

use App\Domain\Preparation\Geometry\DeclaredCoordinateConvention;
use App\Domain\Preparation\Geometry\FacadeCoordinateTranslator;
use App\Domain\Preparation\Geometry\NativeRect;
use App\Domain\Preparation\Geometry\PageBox;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use App\Domain\Preparation\Geometry\UndeclaredCoordinateConventionException;
use PHPUnit\Framework\TestCase;

/**
 * The compatibility profile disagreement that issue #4 exists to settle: one consumer
 * documents 0-100 percentages, the reviewed vendor examples contain values above 100.
 * Both are representable; neither is guessable. These tests pin the rule that the
 * convention is declared and that a field placed through the facade lands on exactly the
 * same rectangle as the same field written in native JSON.
 */
final class FacadeCoordinateTranslatorTest extends TestCase
{
    private static function letterPage(): PageGeometry
    {
        $box = new PageBox(0.0, 0.0, 612.0, 792.0);

        return new PageGeometry(1, $box, $box, PageRotation::None);
    }

    public function test_an_undeclared_convention_is_an_error_not_a_guess(): void
    {
        $this->expectException(UndeclaredCoordinateConventionException::class);
        $this->expectExceptionMessage('did not declare a coordinate convention');

        (new FacadeCoordinateTranslator)->toNative(null, self::letterPage(), 60.0, 650.0, 170.0, 36.0, 'firma');
    }

    public function test_the_same_numbers_mean_different_rectangles_under_different_declarations(): void
    {
        $translator = new FacadeCoordinateTranslator;
        $page = self::letterPage();

        $asPoints = $translator->toNative(DeclaredCoordinateConvention::NativePointsTopLeft, $page, 60.0, 65.0, 17.0, 3.0);
        $asPercent = $translator->toNative(DeclaredCoordinateConvention::PercentOfPageTopLeft, $page, 60.0, 65.0, 17.0, 3.0);

        $this->assertSame([60.0, 65.0, 17.0, 3.0], $asPoints->toArray());
        // 60% of 612 = 367.2, 65% of 792 = 514.8, 17% of 612 = 104.04, 3% of 792 = 23.76
        $this->assertTrue(
            $asPercent->equals(new NativeRect(367.2, 514.8, 104.04, 23.76), 1.0e-9),
            'Percentage conversion produced ['.implode(' ', $asPercent->toArray()).'].',
        );
    }

    public function test_a_field_placed_through_the_percentage_facade_lands_on_the_native_rectangle(): void
    {
        $translator = new FacadeCoordinateTranslator;
        $page = self::letterPage();

        // The signature box from the native example in docs/HANDOFF.md section 7,
        // expressed as percentages of a 612x792 page.
        $native = new NativeRect(61.2, 158.4, 183.6, 39.6);
        $viaFacade = $translator->toNative(
            DeclaredCoordinateConvention::PercentOfPageTopLeft,
            $page,
            10.0,
            20.0,
            30.0,
            5.0,
        );

        $this->assertTrue(
            $native->equals($viaFacade, 1.0e-9),
            'Facade rectangle ['.implode(' ', $viaFacade->toArray()).'] must equal the native rectangle.',
        );
    }

    public function test_a_bottom_left_profile_is_converted_by_declaration(): void
    {
        $translator = new FacadeCoordinateTranslator;
        $page = self::letterPage();

        // y = 456 measured up from the bottom, box 36 high -> native y = 792 - 456 - 36 = 300
        $rect = $translator->toNative(DeclaredCoordinateConvention::PointsBottomLeft, $page, 100.0, 456.0, 170.0, 36.0);

        $this->assertSame([100.0, 300.0, 170.0, 36.0], $rect->toArray());
    }

    public function test_conversions_round_trip_back_to_the_callers_convention(): void
    {
        $translator = new FacadeCoordinateTranslator;
        $page = self::letterPage();
        $rect = new NativeRect(61.2, 158.4, 183.6, 39.6);

        foreach (DeclaredCoordinateConvention::cases() as $convention) {
            $external = $translator->fromNative($convention, $page, $rect);
            $back = $translator->toNative(
                $convention,
                $page,
                $external->x,
                $external->y,
                $external->width,
                $external->height,
            );

            $this->assertTrue(
                $rect->equals($back, 1.0e-9),
                $convention->value.' did not round trip: ['.implode(' ', $back->toArray()).'].',
            );
        }
    }

    public function test_percentages_are_relative_to_the_displayed_page_on_a_rotated_page(): void
    {
        $box = new PageBox(0.0, 0.0, 612.0, 792.0);
        $rotated = new PageGeometry(1, $box, $box, PageRotation::Clockwise90);

        // Displayed page is 792 x 612, so 50% x 50% is (396, 306).
        $rect = (new FacadeCoordinateTranslator)->toNative(
            DeclaredCoordinateConvention::PercentOfPageTopLeft,
            $rotated,
            50.0,
            50.0,
            10.0,
            10.0,
        );

        $this->assertTrue(
            $rect->equals(new NativeRect(396.0, 306.0, 79.2, 61.2), 1.0e-9),
            'Rotated-page percentage conversion produced ['.implode(' ', $rect->toArray()).'].',
        );
    }

    public function test_reverse_conversion_also_requires_a_declaration(): void
    {
        $this->expectException(UndeclaredCoordinateConventionException::class);

        (new FacadeCoordinateTranslator)->fromNative(null, self::letterPage(), new NativeRect(0.0, 0.0, 1.0, 1.0));
    }
}
