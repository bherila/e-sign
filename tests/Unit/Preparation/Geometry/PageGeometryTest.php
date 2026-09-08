<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation\Geometry;

use App\Domain\Preparation\Geometry\CoordinateSpace;
use App\Domain\Preparation\Geometry\InvalidGeometryException;
use App\Domain\Preparation\Geometry\PageBox;
use App\Domain\Preparation\Geometry\PageGeometry;
use App\Domain\Preparation\Geometry\PageRotation;
use PHPUnit\Framework\TestCase;

final class PageGeometryTest extends TestCase
{
    public function test_page_box_normalises_reversed_corners(): void
    {
        $box = new PageBox(612.0, 792.0, 0.0, 0.0);

        $this->assertSame([0.0, 0.0, 612.0, 792.0], $box->toArray());
        $this->assertSame(612.0, $box->width());
        $this->assertSame(792.0, $box->height());
    }

    public function test_a_degenerate_page_box_is_rejected(): void
    {
        $this->expectException(InvalidGeometryException::class);

        new PageBox(10.0, 10.0, 10.0, 400.0);
    }

    public function test_a_non_finite_page_box_is_rejected(): void
    {
        $this->expectException(InvalidGeometryException::class);

        new PageBox(0.0, 0.0, INF, 400.0);
    }

    public function test_the_crop_box_is_clipped_to_the_media_box(): void
    {
        $geometry = PageGeometry::create(
            1,
            new PageBox(0.0, 0.0, 612.0, 792.0),
            new PageBox(-50.0, -50.0, 700.0, 900.0),
            PageRotation::None,
        );

        $this->assertSame([0.0, 0.0, 612.0, 792.0], $geometry->cropBox->toArray());
    }

    public function test_a_crop_box_outside_the_media_box_is_rejected(): void
    {
        $this->expectException(InvalidGeometryException::class);

        PageGeometry::create(
            1,
            new PageBox(0.0, 0.0, 612.0, 792.0),
            new PageBox(700.0, 900.0, 800.0, 1000.0),
            PageRotation::None,
        );
    }

    public function test_a_missing_crop_box_defaults_to_the_media_box(): void
    {
        $media = new PageBox(0.0, 0.0, 595.0, 842.0);
        $geometry = PageGeometry::create(1, $media, null, PageRotation::None);

        $this->assertTrue($geometry->cropBox->equals($media));
    }

    public function test_page_numbers_are_one_based(): void
    {
        $this->expectException(InvalidGeometryException::class);

        new PageGeometry(0, new PageBox(0.0, 0.0, 1.0, 1.0), new PageBox(0.0, 0.0, 1.0, 1.0), PageRotation::None);
    }

    public function test_user_unit_must_be_positive(): void
    {
        $this->expectException(InvalidGeometryException::class);

        new PageGeometry(1, new PageBox(0.0, 0.0, 1.0, 1.0), new PageBox(0.0, 0.0, 1.0, 1.0), PageRotation::None, 0.0);
    }

    public function test_rotation_normalises_negative_and_oversized_angles(): void
    {
        $this->assertSame(PageRotation::Clockwise270, PageRotation::fromDegrees(-90));
        $this->assertSame(PageRotation::Clockwise90, PageRotation::fromDegrees(450));
        $this->assertSame(PageRotation::None, PageRotation::fromDegrees(720));
    }

    public function test_a_rotation_that_is_not_a_quarter_turn_is_rejected(): void
    {
        $this->expectException(InvalidGeometryException::class);

        PageRotation::fromDegrees(45);
    }

    public function test_the_coordinate_space_descriptor_matches_the_handoff_schema(): void
    {
        $this->assertSame([
            'unit' => 'pt',
            'origin' => 'top-left',
            'page_box' => 'crop',
            'rotation' => 'displayed',
            'page_index_base' => 1,
        ], CoordinateSpace::descriptor());

        CoordinateSpace::assertSupported(CoordinateSpace::descriptor());
        $this->addToAssertionCount(1);
    }

    public function test_an_undeclared_coordinate_space_key_is_rejected(): void
    {
        $descriptor = CoordinateSpace::descriptor();
        unset($descriptor['origin']);

        $this->expectException(InvalidGeometryException::class);
        $this->expectExceptionMessage('coordinate_space.origin must be declared explicitly.');

        CoordinateSpace::assertSupported($descriptor);
    }

    public function test_a_different_coordinate_space_is_rejected_rather_than_converted(): void
    {
        $descriptor = CoordinateSpace::descriptor();
        $descriptor['origin'] = 'bottom-left';

        $this->expectException(InvalidGeometryException::class);
        $this->expectExceptionMessage('Unsupported coordinate_space.origin');

        CoordinateSpace::assertSupported($descriptor);
    }
}
