<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Text\TextExtractionException;

/**
 * Builds a PdfFontModel from a /Font resource dictionary.
 *
 * Supported: simple fonts (Type1, TrueType, MMType1) with /Widths, and Type0 fonts with
 * an Identity CMap over a CIDFontType0/2 descendant. Everything else raises, because a
 * font whose codes cannot be measured or decoded would produce anchor rectangles that
 * look plausible and are wrong.
 */
final readonly class FontDictionaryReader
{
    /** Nominal em-box used when the font has no /FontDescriptor (the core 14, typically). */
    private const FALLBACK_ASCENT = 0.8;

    private const FALLBACK_DESCENT = 0.2;

    /** Code points where CP1252 (WinAnsiEncoding) departs from ISO-8859-1. */
    private const WINANSI_HIGH = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026,
        0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160,
        0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019,
        0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
        0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /**
     * @param  PreflightBudget  $budget  The document's, charged while a /ToUnicode map is read.
     */
    public function __construct(private PdfObjectGraph $graph, private PreflightBudget $budget) {}

    /**
     * @param  array<string, array<int, mixed>>  $fontDict
     */
    public function read(string $resourceName, array $fontDict): PdfFontModel
    {
        $subtype = $this->graph->dictEntryAsName($fontDict, 'Subtype');

        return match ($subtype) {
            'Type0' => $this->readComposite($resourceName, $fontDict),
            'Type1', 'TrueType', 'MMType1' => $this->readSimple($resourceName, $fontDict),
            'Type3' => throw new TextExtractionException(
                'Font /'.$resourceName.' is a Type3 font. Type3 glyph procedures define their own '
                .'coordinate space, so run widths cannot be measured reliably and anchor placement '
                .'is not supported on this document.',
            ),
            default => throw new TextExtractionException(
                'Font /'.$resourceName.' has an unsupported /Subtype ('.($subtype ?? 'missing').').',
            ),
        };
    }

    /**
     * @param  array<string, array<int, mixed>>  $fontDict
     */
    private function readSimple(string $resourceName, array $fontDict): PdfFontModel
    {
        $firstChar = $this->graph->dictEntryAsInt($fontDict, 'FirstChar') ?? 0;
        $widthValues = $this->graph->dictEntryAsNumbers($fontDict, 'Widths') ?? [];

        $widths = [];
        foreach ($widthValues as $index => $width) {
            $widths[$firstChar + $index] = $width;
        }

        $descriptor = $this->graph->dictEntryAsDictionary($fontDict, 'FontDescriptor');
        $defaultWidth = $descriptor !== null
            ? ($this->graph->dictEntryAsFloat($descriptor, 'MissingWidth') ?? 0.0)
            : 500.0;

        if ($widths === []) {
            // Core-14 fonts may legally omit /Widths; without bundled AFM metrics the run
            // width would be a guess, and a guessed width silently moves an anchor box.
            throw new TextExtractionException(
                'Font /'.$resourceName.' has no /Widths array. Standard-14 font metrics are not '
                .'bundled, so text run widths cannot be measured for this document.',
            );
        }

        [$ascent, $descent] = $this->emBox($descriptor);

        return new PdfFontModel(
            $resourceName,
            false,
            $widths,
            $defaultWidth,
            $this->toUnicodeMap($fontDict),
            $this->simpleEncoding($fontDict),
            $ascent,
            $descent,
        );
    }

    /**
     * @param  array<string, array<int, mixed>>  $fontDict
     */
    private function readComposite(string $resourceName, array $fontDict): PdfFontModel
    {
        $encoding = $this->graph->dictEntryAsName($fontDict, 'Encoding');
        if ($encoding !== 'Identity-H' && $encoding !== 'Identity-V') {
            throw new TextExtractionException(
                'Font /'.$resourceName.' uses the CMap /'.($encoding ?? 'unknown').'. Only Identity '
                .'encodings are supported; predefined CJK CMaps would need their code-to-CID tables '
                .'to place text correctly.',
            );
        }

        $descendants = $this->graph->dictEntryAsArray($fontDict, 'DescendantFonts') ?? [];
        $descendant = null;
        foreach ($descendants as $entry) {
            $resolved = $this->graph->resolve($entry);
            if (is_array($resolved) && ($resolved[0] ?? null) === '<<') {
                $descendant = $this->graph->decodeDictionary($resolved);
                break;
            }
        }

        if ($descendant === null) {
            throw new TextExtractionException('Font /'.$resourceName.' has no usable /DescendantFonts entry.');
        }

        $defaultWidth = $this->graph->dictEntryAsFloat($descendant, 'DW') ?? 1000.0;
        $descriptor = $this->graph->dictEntryAsDictionary($descendant, 'FontDescriptor');
        [$ascent, $descent] = $this->emBox($descriptor);

        return new PdfFontModel(
            $resourceName,
            true,
            $this->cidWidths($descendant),
            $defaultWidth,
            $this->toUnicodeMap($fontDict),
            [],
            $ascent,
            $descent,
        );
    }

    /**
     * Parse the /W array of a CIDFont: `c [w ...]` runs and `cFirst cLast w` ranges.
     *
     * @param  array<string, array<int, mixed>>  $descendant
     * @return array<int, float>
     */
    private function cidWidths(array $descendant): array
    {
        $entries = $this->graph->dictEntryAsArray($descendant, 'W');
        if ($entries === null) {
            return [];
        }

        $widths = [];
        $index = 0;
        $count = count($entries);
        while ($index < $count) {
            $first = $this->graph->resolve($entries[$index] ?? null);
            if (! is_array($first) || ($first[0] ?? null) !== 'numeric') {
                $index++;

                continue;
            }

            $start = (int) $first[1];
            $next = $this->graph->resolve($entries[$index + 1] ?? null);

            if (is_array($next) && ($next[0] ?? null) === '[' && is_array($next[1] ?? null)) {
                $offset = 0;
                foreach ($next[1] as $item) {
                    if (is_array($item) && ($item[0] ?? null) === 'numeric') {
                        $widths[$start + $offset] = (float) $item[1];
                        $offset++;
                    }
                }
                $index += 2;

                continue;
            }

            $third = $this->graph->resolve($entries[$index + 2] ?? null);
            if (
                is_array($next) && ($next[0] ?? null) === 'numeric'
                && is_array($third) && ($third[0] ?? null) === 'numeric'
            ) {
                $end = (int) $next[1];
                $width = (float) $third[1];
                if ($end >= $start && $end - $start <= 65_535) {
                    for ($cid = $start; $cid <= $end; $cid++) {
                        $widths[$cid] = $width;
                    }
                }
                $index += 3;

                continue;
            }

            $index++;
        }

        return $widths;
    }

    /**
     * @param  array<string, array<int, mixed>>|null  $descriptor
     * @return array{float, float} Ascent and (positive) descent as fractions of an em.
     */
    private function emBox(?array $descriptor): array
    {
        if ($descriptor === null) {
            return [self::FALLBACK_ASCENT, self::FALLBACK_DESCENT];
        }

        $ascent = $this->graph->dictEntryAsFloat($descriptor, 'Ascent');
        $descent = $this->graph->dictEntryAsFloat($descriptor, 'Descent');

        return [
            $ascent !== null && $ascent > 0.0 ? $ascent / 1000.0 : self::FALLBACK_ASCENT,
            $descent !== null && $descent !== 0.0 ? abs($descent) / 1000.0 : self::FALLBACK_DESCENT,
        ];
    }

    /**
     * @param  array<string, array<int, mixed>>  $fontDict
     * @return array<int, string>
     */
    private function toUnicodeMap(array $fontDict): array
    {
        $ref = $this->graph->dictEntryRef($fontDict, 'ToUnicode');
        if ($ref === null) {
            return [];
        }

        $cmap = $this->graph->streamData($ref);

        return $cmap === null ? [] : (new ToUnicodeCMapReader($this->budget))->parse($cmap);
    }

    /**
     * Build the code => UTF-8 table for a simple font.
     *
     * WinAnsiEncoding is assumed unless /Encoding names another base encoding, which
     * matches what every producer in the fixture matrix emits. /Differences entries are
     * honoured only for `uniXXXX`-style glyph names: mapping arbitrary glyph names needs
     * the Adobe Glyph List, which is not bundled. See the limitations section of
     * docs/stage0/pdf-import.md.
     *
     * @param  array<string, array<int, mixed>>  $fontDict
     * @return array<int, string>
     */
    private function simpleEncoding(array $fontDict): array
    {
        $table = [];
        for ($code = 32; $code <= 255; $code++) {
            $codePoint = self::WINANSI_HIGH[$code] ?? $code;
            $table[$code] = $this->utf8($codePoint);
        }

        $encoding = $this->graph->dictEntryAsDictionary($fontDict, 'Encoding');
        if ($encoding === null) {
            return $table;
        }

        $differences = $this->graph->dictEntryAsArray($encoding, 'Differences');
        if ($differences === null) {
            return $table;
        }

        $code = 0;
        foreach ($differences as $entry) {
            $resolved = $this->graph->resolve($entry);
            if (! is_array($resolved)) {
                continue;
            }

            if (($resolved[0] ?? null) === 'numeric') {
                $code = (int) $resolved[1];

                continue;
            }

            if (($resolved[0] ?? null) === '/' && is_string($resolved[1] ?? null)) {
                $mapped = $this->glyphNameToUtf8($resolved[1]);
                if ($mapped !== null) {
                    $table[$code] = $mapped;
                } else {
                    unset($table[$code]);
                }
                $code++;
            }
        }

        return $table;
    }

    private function glyphNameToUtf8(string $name): ?string
    {
        if (preg_match('/^uni([0-9A-Fa-f]{4})$/', $name, $match) === 1) {
            return $this->utf8((int) hexdec($match[1]));
        }

        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $match) === 1) {
            return $this->utf8((int) hexdec($match[1]));
        }

        return null;
    }

    private function utf8(int $codePoint): string
    {
        // Note: `?:` would be wrong here, because mb_chr(48) is the string "0", which is falsy.
        $char = mb_chr($codePoint, 'UTF-8');

        return $char === false ? "\u{FFFD}" : $char;
    }
}
