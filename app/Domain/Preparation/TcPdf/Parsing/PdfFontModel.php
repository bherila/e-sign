<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

/**
 * Just enough of a PDF font to place and decode a text run: how many bytes a code
 * takes, how wide each code is, and what Unicode it stands for.
 *
 * Widths are in 1/1000 of an em, as PDF stores them.
 */
final readonly class PdfFontModel
{
    /**
     * @param  bool  $composite  True for Type0 fonts, whose codes are two bytes wide.
     * @param  array<int, float>  $widths  Character or CID code => width in 1/1000 em.
     * @param  array<int, string>  $toUnicode  Code => UTF-8 string, from /ToUnicode.
     * @param  array<int, string>  $encoding  Code => UTF-8 string, from the simple-font encoding.
     */
    public function __construct(
        public string $resourceName,
        public bool $composite,
        public array $widths,
        public float $defaultWidth,
        public array $toUnicode,
        public array $encoding,
        public float $ascent = 0.8,
        public float $descent = 0.2,
    ) {}

    /**
     * Split raw string bytes into character codes.
     *
     * @return array<int, int>
     */
    public function codes(string $bytes): array
    {
        if (! $this->composite) {
            $codes = [];
            $length = strlen($bytes);
            for ($i = 0; $i < $length; $i++) {
                $codes[] = ord($bytes[$i]);
            }

            return $codes;
        }

        $codes = [];
        $length = strlen($bytes) - (strlen($bytes) % 2);
        for ($i = 0; $i < $length; $i += 2) {
            $codes[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
        }

        return $codes;
    }

    /** Width of one code in 1/1000 em. */
    public function width(int $code): float
    {
        return $this->widths[$code] ?? $this->defaultWidth;
    }

    /**
     * Decode character codes to UTF-8.
     *
     * /ToUnicode wins where it exists; otherwise the simple-font encoding table is
     * used. A code neither map covers becomes U+FFFD rather than being dropped, so a
     * failed decode is visible in the extracted text instead of silently shifting the
     * character offsets an anchor match depends on.
     *
     * @param  array<int, int>  $codes
     */
    public function decode(array $codes): string
    {
        $out = '';
        foreach ($codes as $code) {
            $out .= $this->toUnicode[$code] ?? $this->encoding[$code] ?? "\u{FFFD}";
        }

        return $out;
    }

    /** True when this code has no Unicode mapping at all. */
    public function isUnmapped(int $code): bool
    {
        return ! isset($this->toUnicode[$code]) && ! isset($this->encoding[$code]);
    }
}
