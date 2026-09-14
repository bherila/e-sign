<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;

/**
 * Reads the code => Unicode mapping out of a /ToUnicode CMap stream.
 *
 * The CMap is PostScript-flavoured but lexically compatible with a content stream, so
 * the same tokenizer is used. Only `bfchar` and `bfrange` sections are interpreted:
 * they are the only ones that carry the mapping.
 */
final readonly class ToUnicodeCMapReader
{
    /**
     * @param  PreflightBudget  $budget  The document's. A CMap is a stream of the document being
     *                                   read, and lexing it is as much a part of that read as
     *                                   lexing a page: a font can carry a map of millions of
     *                                   entries inside one allowed stream.
     */
    public function __construct(private PreflightBudget $budget) {}

    /**
     * @return array<int, string> Character code => UTF-8 string.
     *
     * @throws PreflightBudgetException
     */
    public function parse(string $cmap): array
    {
        $map = [];

        foreach ((new ContentStreamTokenizer($cmap, $this->budget))->operations() as $operation) {
            match ($operation->operator) {
                'endbfchar' => $this->readBfChar($operation, $map),
                'endbfrange' => $this->readBfRange($operation, $map),
                default => null,
            };
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $map
     *
     * @throws PreflightBudgetException
     */
    private function record(array &$map, int $code, string $text): void
    {
        $map[$code] = $text;
        $this->budget->expand();
    }

    /**
     * @param  array<int, string>  $map
     *
     * @throws PreflightBudgetException
     */
    private function readBfChar(ContentStreamOperation $operation, array &$map): void
    {
        $operands = $operation->operands;
        $count = count($operands);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $src = $this->codeOf($operands[$i]);
            $dst = $this->textOf($operands[$i + 1]);
            if ($src !== null && $dst !== null) {
                $this->record($map, $src, $dst);
            }
        }
    }

    /**
     * @param  array<int, string>  $map
     *
     * @throws PreflightBudgetException
     */
    private function readBfRange(ContentStreamOperation $operation, array &$map): void
    {
        $operands = $operation->operands;
        $count = count($operands);
        for ($i = 0; $i + 2 < $count; $i += 3) {
            $low = $this->codeOf($operands[$i]);
            $high = $this->codeOf($operands[$i + 1]);
            if ($low === null || $high === null || $high < $low || $high - $low > 65_535) {
                continue;
            }

            $target = $operands[$i + 2];

            if (is_array($target) && $target[0] === 'array' && is_array($target[1])) {
                foreach (array_values($target[1]) as $offset => $item) {
                    $text = $this->textOf($item);
                    if ($text !== null && $low + $offset <= $high) {
                        $this->record($map, $low + $offset, $text);
                    }
                }

                continue;
            }

            $base = $this->codePointOf($target);
            if ($base === null) {
                continue;
            }

            for ($code = $low; $code <= $high; $code++) {
                $this->record($map, $code, $this->utf8($base + ($code - $low)));
            }
        }
    }

    /** @param mixed $operand */
    private function codeOf($operand): ?int
    {
        if (! is_array($operand) || $operand[0] !== 'hex' || ! is_string($operand[1])) {
            return null;
        }

        $bytes = $operand[1];
        if ($bytes === '' || strlen($bytes) > 4) {
            return null;
        }

        $value = 0;
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /** UTF-16BE destination bytes decoded to UTF-8. @param mixed $operand */
    private function textOf($operand): ?string
    {
        if (! is_array($operand) || $operand[0] !== 'hex' || ! is_string($operand[1]) || $operand[1] === '') {
            return null;
        }

        return $this->utf16BeToUtf8($operand[1]);
    }

    /** First code point of a destination, used to seed a bfrange. @param mixed $operand */
    private function codePointOf($operand): ?int
    {
        $text = $this->textOf($operand);
        if ($text === null || $text === '') {
            return null;
        }

        $codePoint = mb_ord($text, 'UTF-8');

        return $codePoint === false ? null : $codePoint;
    }

    /** Note: `?:` would be wrong here, because mb_chr(48) is the string "0", which is falsy. */
    private function utf8(int $codePoint): string
    {
        $char = mb_chr($codePoint, 'UTF-8');

        return $char === false ? "\u{FFFD}" : $char;
    }

    /**
     * UTF-16BE to UTF-8, decoded in slices that are each reported before they are unpacked.
     *
     * One destination can be most of a stream. Unpacking it whole built an integer array holding
     * every code unit — many times the memory of the string it came from — before `record()` got
     * as far as the budget. A slice is the budget's own scan interval and even, so it never splits
     * a code unit; a surrogate pair that straddles two slices is carried across the boundary.
     *
     * @throws PreflightBudgetException
     */
    private function utf16BeToUtf8(string $bytes): string
    {
        if (strlen($bytes) % 2 === 1) {
            $bytes .= "\x00";
        }

        $out = '';
        $pendingHigh = null;
        $length = strlen($bytes);

        for ($offset = 0; $offset < $length; $offset += PreflightBudget::SCAN_BYTES_PER_TICK) {
            $slice = substr($bytes, $offset, PreflightBudget::SCAN_BYTES_PER_TICK);
            $this->budget->scan(strlen($slice));

            $units = unpack('n*', $slice);
            if ($units === false) {
                return "\u{FFFD}";
            }

            foreach ($units as $unit) {
                if ($pendingHigh !== null) {
                    if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                        $out .= $this->utf8(0x10000 + (($pendingHigh - 0xD800) << 10) + ($unit - 0xDC00));
                        $pendingHigh = null;

                        continue;
                    }

                    // A high surrogate with no low one after it stands alone, as it did before.
                    $out .= $this->utf8($pendingHigh);
                    $pendingHigh = null;
                }

                if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                    $pendingHigh = $unit;

                    continue;
                }

                $out .= $this->utf8($unit);
            }
        }

        if ($pendingHigh !== null) {
            $out .= $this->utf8($pendingHigh);
        }

        return $out;
    }
}
