<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

use App\Domain\Preparation\Geometry\NativeRect;

/**
 * One positioned run of text taken from a page content stream.
 *
 * A run is exactly what a single text-showing operator drew with one text matrix.
 * It is not a word, a line, or a paragraph: PDF producers are free to split a
 * visual line across many runs, and this type deliberately does not guess where
 * the seams are. See docs/stage0/pdf-import.md for the limitations that follow.
 */
final readonly class TextRun
{
    /**
     * @param  int  $page  1-based page number.
     * @param  string  $text  UTF-8 text as decoded from the font encoding or /ToUnicode CMap.
     * @param  NativeRect  $rect  Nominal box: advance width by (ascent + descent) of the font size.
     * @param  TextDirection  $direction  Direction of the advance in native space. `Other` means the
     *                                    run is rotated off-axis and `rect` is only a bounding box.
     */
    public function __construct(
        public int $page,
        public string $text,
        public NativeRect $rect,
        public float $fontSize,
        public string $fontResource,
        public TextDirection $direction = TextDirection::LeftToRight,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'text' => $this->text,
            'rect' => $this->rect->toArray(),
            'font_size' => $this->fontSize,
            'font_resource' => $this->fontResource,
            'direction' => $this->direction->value,
        ];
    }
}
