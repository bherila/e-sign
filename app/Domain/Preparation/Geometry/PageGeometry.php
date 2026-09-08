<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Geometry;

/**
 * Everything the native coordinate space needs to know about one page.
 *
 * `pageNumber` is 1-based. `cropBox` is already clipped to `mediaBox` (ISO 32000-1
 * 14.11.2 requires readers to do that), so it is the box the viewer displays.
 */
final readonly class PageGeometry
{
    public function __construct(
        public int $pageNumber,
        public PageBox $mediaBox,
        public PageBox $cropBox,
        public PageRotation $rotation,
        public float $userUnit = 1.0,
    ) {
        if ($pageNumber < 1) {
            throw new InvalidGeometryException('Page numbers are 1-based; got '.$pageNumber.'.');
        }

        if (! is_finite($userUnit) || $userUnit <= 0.0) {
            throw new InvalidGeometryException('/UserUnit must be a positive finite number.');
        }
    }

    /**
     * Build a page geometry, clipping the CropBox to the MediaBox and defaulting a
     * missing CropBox to the MediaBox.
     */
    public static function create(
        int $pageNumber,
        PageBox $mediaBox,
        ?PageBox $cropBox,
        PageRotation $rotation,
        float $userUnit = 1.0,
    ): self {
        $effective = $cropBox instanceof PageBox ? $cropBox->intersect($mediaBox) : $mediaBox;
        if (! $effective instanceof PageBox) {
            throw new InvalidGeometryException(
                'Page '.$pageNumber.' has a CropBox that does not intersect its MediaBox.',
            );
        }

        return new self($pageNumber, $mediaBox, $effective, $rotation, $userUnit);
    }

    /** Width of the displayed page in native units. */
    public function nativeWidth(): float
    {
        return $this->rotation->swapsAxes() ? $this->cropBox->height() : $this->cropBox->width();
    }

    /** Height of the displayed page in native units. */
    public function nativeHeight(): float
    {
        return $this->rotation->swapsAxes() ? $this->cropBox->width() : $this->cropBox->height();
    }

    /** The whole displayed page as a native rectangle. */
    public function nativePageRect(): NativeRect
    {
        return new NativeRect(0.0, 0.0, $this->nativeWidth(), $this->nativeHeight());
    }

    /**
     * Physical width in real points (1/72 inch), i.e. native units scaled by /UserUnit.
     * This is a declared conversion, never applied implicitly to field coordinates.
     */
    public function physicalWidthPt(): float
    {
        return $this->nativeWidth() * $this->userUnit;
    }

    public function physicalHeightPt(): float
    {
        return $this->nativeHeight() * $this->userUnit;
    }

    public function withPageNumber(int $pageNumber): self
    {
        return new self($pageNumber, $this->mediaBox, $this->cropBox, $this->rotation, $this->userUnit);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'page' => $this->pageNumber,
            'media_box' => $this->mediaBox->toArray(),
            'crop_box' => $this->cropBox->toArray(),
            'rotation' => $this->rotation->value,
            'user_unit' => $this->userUnit,
            'native_width' => $this->nativeWidth(),
            'native_height' => $this->nativeHeight(),
        ];
    }
}
