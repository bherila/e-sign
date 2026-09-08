<?php

declare(strict_types=1);

namespace Tests\Fixtures\Pdf;

/**
 * Builds a tiny synthetic TrueType font program so the fixture matrix can contain a
 * genuinely embedded Unicode font without shipping any third-party font binary.
 *
 * Every glyph is the same filled rectangle with a 600/1000 advance. The font is not
 * meant to be legible; it exists so that import fidelity, /FontFile2 preservation and
 * Identity-H + /ToUnicode text extraction can be exercised on fully synthetic content.
 */
final class SyntheticTrueTypeFont
{
    public const UNITS_PER_EM = 1000;

    public const ADVANCE_WIDTH = 600;

    public const ASCENT = 800;

    public const DESCENT = -200;

    /** @var array<int, int> Unicode code point => glyph id (glyph 0 is .notdef). */
    private array $codeToGlyph = [];

    /** @param array<int, int> $codePoints Unicode code points in glyph order. */
    public function __construct(private readonly array $codePoints)
    {
        $glyphId = 1;
        foreach ($codePoints as $codePoint) {
            if ($codePoint > 0xFFFF) {
                throw new \InvalidArgumentException('Fixture font supports BMP code points only.');
            }
            $this->codeToGlyph[$codePoint] = $glyphId++;
        }
    }

    /** @return array<int, int> */
    public function codeToGlyph(): array
    {
        return $this->codeToGlyph;
    }

    public function glyphCount(): int
    {
        return count($this->codePoints) + 1;
    }

    public function build(): string
    {
        $numGlyphs = $this->glyphCount();

        $glyf = '';
        $loca = pack('n', 0); // .notdef has zero length
        $glyphBody = $this->rectangleGlyph();
        for ($i = 1; $i < $numGlyphs; $i++) {
            $glyf .= $glyphBody;
            $loca .= pack('n', intdiv(strlen($glyf), 2));
        }
        $loca .= pack('n', intdiv(strlen($glyf), 2));

        $hmtx = '';
        for ($i = 0; $i < $numGlyphs; $i++) {
            $hmtx .= pack('nn', self::ADVANCE_WIDTH, 50);
        }

        $tables = [
            'OS/2' => $this->os2Table(),
            'cmap' => $this->cmapTable(),
            'glyf' => $glyf,
            'head' => $this->headTable(),
            'hhea' => $this->hheaTable($numGlyphs),
            'hmtx' => $hmtx,
            'loca' => $loca,
            'maxp' => $this->maxpTable($numGlyphs),
            'name' => $this->nameTable(),
            'post' => $this->postTable(),
        ];
        ksort($tables);

        return $this->assemble($tables);
    }

    private function rectangleGlyph(): string
    {
        return pack('nnnnn', 1, 50, 0, 550, 700)   // numberOfContours, xMin, yMin, xMax, yMax
            .pack('n', 3)                          // endPtsOfContours[0]
            .pack('n', 0)                          // instructionLength
            .str_repeat(chr(0x01), 4)              // flags: on-curve, 16-bit deltas
            .pack('n*', 50, 500, 0, 65036)         // x deltas: 50, 500, 0, -500
            .pack('n*', 0, 0, 700, 0);             // y deltas
    }

    private function headTable(): string
    {
        return pack('NN', 0x00010000, 0x00010000)
            .pack('N', 0)              // checkSumAdjustment, patched in assemble()
            .pack('N', 0x5F0F3CF5)     // magicNumber
            .pack('nn', 0x0003, self::UNITS_PER_EM)
            .str_repeat("\x00", 16)    // created + modified
            .pack('nnnn', 50, 0, 550, 700)
            .pack('nnn', 0, 8, 2)      // macStyle, lowestRecPPEM, fontDirectionHint
            .pack('nn', 0, 0);         // indexToLocFormat (short), glyphDataFormat
    }

    private function hheaTable(int $numGlyphs): string
    {
        return pack('N', 0x00010000)
            .pack('nnn', self::ASCENT, 65536 + self::DESCENT, 0)
            .pack('n', self::ADVANCE_WIDTH)
            .pack('nnn', 50, 50, 550)
            .pack('nnn', 1, 0, 0)
            .str_repeat("\x00", 8)
            .pack('nn', 0, $numGlyphs);
    }

    private function maxpTable(int $numGlyphs): string
    {
        return pack('N', 0x00010000)
            .pack('n', $numGlyphs)
            .pack('nn', 4, 1)
            .pack('nn', 0, 0)
            .pack('nn', 2, 0)
            .str_repeat("\x00", 14);
    }

    private function cmapTable(): string
    {
        $codes = array_keys($this->codeToGlyph);
        sort($codes);

        $segments = [];
        foreach ($codes as $code) {
            $segments[] = [$code, $code, ($this->codeToGlyph[$code] - $code) & 0xFFFF];
        }
        $segments[] = [0xFFFF, 0xFFFF, 1];

        $segCount = count($segments);
        $searchRange = 2 * (2 ** (int) floor(log($segCount, 2)));
        $sub = pack('nnn', 4, 0, 0) // format, length placeholder handled below, language
            .pack('nnnn', $segCount * 2, $searchRange, (int) floor(log($segCount, 2)), $segCount * 2 - $searchRange);
        foreach ($segments as $segment) {
            $sub .= pack('n', $segment[1]);
        }
        $sub .= pack('n', 0);
        foreach ($segments as $segment) {
            $sub .= pack('n', $segment[0]);
        }
        foreach ($segments as $segment) {
            $sub .= pack('n', $segment[2]);
        }
        $sub .= str_repeat("\x00\x00", $segCount); // idRangeOffset

        $sub = substr_replace($sub, pack('n', strlen($sub)), 2, 2);

        return pack('nn', 0, 1).pack('nnN', 3, 1, 12).$sub;
    }

    private function nameTable(): string
    {
        $value = '';
        foreach (str_split('ESignFixtureFont') as $char) {
            $value .= "\x00".$char;
        }
        $header = pack('nnn', 0, 1, 18);
        $record = pack('nnnnnn', 3, 1, 0x0409, 6, strlen($value), 0);

        return $header.$record.$value;
    }

    private function postTable(): string
    {
        return pack('NN', 0x00030000, 0)
            .pack('nn', 65536 - 100, 50)
            .pack('N', 1)
            .str_repeat("\x00", 16);
    }

    private function os2Table(): string
    {
        // OS/2 version 4: exactly 96 bytes.
        return pack('n', 4)                                  // version
            .pack('n', self::ADVANCE_WIDTH)                  // xAvgCharWidth
            .pack('nn', 400, 5)                              // usWeightClass, usWidthClass
            .pack('n', 0)                                    // fsType: installable
            .pack('nnnn', 650, 700, 0, 140)                  // ySubscript X/Y size, X/Y offset
            .pack('nnnn', 650, 700, 0, 480)                  // ySuperscript X/Y size, X/Y offset
            .pack('nn', 50, 250)                             // yStrikeoutSize, yStrikeoutPosition
            .pack('n', 0)                                    // sFamilyClass
            .str_repeat("\x00", 10)                          // panose
            .pack('NNNN', 0, 0, 0, 0)                        // ulUnicodeRange1..4
            .'ESGN'                                          // achVendID
            .pack('n', 0x0040)                               // fsSelection: regular
            .pack('nn', 0x20, 0xFFFF)                        // usFirstCharIndex, usLastCharIndex
            .pack('nnn', self::ASCENT, 65536 + self::DESCENT, 0)
            .pack('nn', self::ASCENT, -self::DESCENT)        // usWinAscent, usWinDescent
            .pack('NN', 1, 0)                                // ulCodePageRange1..2
            .pack('nn', 500, 700)                            // sxHeight, sCapHeight
            .pack('nnn', 0x20, 0x20, 1);                     // usDefaultChar, usBreakChar, usMaxContext
    }

    /** @param array<string, string> $tables */
    private function assemble(array $tables): string
    {
        $numTables = count($tables);
        $searchRange = 16 * (2 ** (int) floor(log($numTables, 2)));
        $header = pack('Nnnnn', 0x00010000, $numTables, $searchRange, (int) floor(log($numTables, 2)), $numTables * 16 - $searchRange);

        $offset = 12 + 16 * $numTables;
        $directory = '';
        $body = '';
        foreach ($tables as $tag => $data) {
            $padded = $data.str_repeat("\x00", (4 - strlen($data) % 4) % 4);
            $directory .= str_pad((string) $tag, 4).pack('NNN', $this->checksum($padded), $offset, strlen($data));
            $body .= $padded;
            $offset += strlen($padded);
        }

        $font = $header.$directory.$body;

        // Patch head.checkSumAdjustment.
        $headOffset = 12 + 16 * array_search('head', array_keys($tables), true);
        $headStart = unpack('N', substr($font, $headOffset + 8, 4))[1];
        $adjustment = (0xB1B0AFBA - $this->checksum($font)) & 0xFFFFFFFF;

        return substr_replace($font, pack('N', $adjustment), $headStart + 8, 4);
    }

    private function checksum(string $data): int
    {
        $data .= str_repeat("\x00", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
