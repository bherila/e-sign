<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

use App\Domain\Preparation\Preflight\PreflightBudget;
use App\Domain\Preparation\Preflight\PreflightBudgetException;

/**
 * Lexes a decoded PDF content stream into operators and their operands.
 *
 * This is a lexer over already-decompressed content, which is the only way to read
 * positions correctly: a regular expression over compressed bytes cannot see the text
 * matrix, and a regular expression over decompressed bytes still cannot tell a `(Tj)`
 * inside a string from the operator that follows it.
 *
 * Operand shapes returned in `operands`:
 *   ['num', float] ['str', string] ['hex', string] ['name', string]
 *   ['array', array<int, operand>] ['dict', array<int, operand>] ['bool', bool] ['null', null]
 */
final class ContentStreamTokenizer
{
    private const WHITESPACE = " \t\r\n\f\0";

    private const DELIMITERS = '()<>[]{}/%';

    /** Bounded so a malformed stream cannot grow the operand list without limit. */
    private const MAX_OPERANDS = 4096;

    /**
     * Bytes advanced over between two looks at the budget.
     *
     * A caller that charges per yielded operation does not see this work: a stream of
     * operands with no operator yields nothing at all, and a single operation can carry an
     * array of thousands of tokens. So the tokenizer charges its own work.
     *
     * The unit is the byte, not the token, because a token is not a bounded amount of work:
     * one comment, one run of whitespace, or one string can be the whole stream. Every loop
     * that advances the offset looks at it, so no loop — however few tokens it yields — scans
     * more than this many bytes without the budget being consulted. Every token consumes at
     * least one byte, so tokens are bounded by the same count.
     */
    private const BYTES_PER_TICK = 4096;

    private int $offset = 0;

    private int $length;

    /** The offset at which the budget is next consulted. */
    private int $checkAt = self::BYTES_PER_TICK;

    /**
     * @param  PreflightBudget  $budget  The running cost of reading the document this stream
     *                                   belongs to. Required rather than defaulted: a content
     *                                   stream is read after its document was parsed under a
     *                                   budget, and lexing it is part of the same read.
     */
    public function __construct(
        private readonly string $data,
        private readonly PreflightBudget $budget,
    ) {
        $this->length = strlen($data);
    }

    /**
     * @return \Generator<int, ContentStreamOperation>
     *
     * @throws PreflightBudgetException
     */
    public function operations(): \Generator
    {
        $operands = [];
        while (($token = $this->nextToken()) !== null) {
            if ($token[0] === 'operator') {
                /** @var string $name */
                $name = $token[1];
                yield new ContentStreamOperation($name, $operands);
                $operands = [];

                if ($name === 'BI') {
                    $this->skipInlineImage();
                }

                continue;
            }

            // A pathological stream of operands with no operator must not grow forever.
            if (count($operands) < self::MAX_OPERANDS) {
                $operands[] = $token;
            }
        }
    }

    /** @return array{string, mixed}|null */
    private function nextToken(): ?array
    {
        $this->skipWhitespaceAndComments();
        if ($this->offset >= $this->length) {
            return null;
        }

        $char = $this->data[$this->offset];

        return match (true) {
            $char === '(' => ['str', $this->readLiteralString()],
            $char === '<' && ($this->data[$this->offset + 1] ?? '') === '<' => $this->readDictionary(),
            $char === '<' => ['hex', $this->readHexString()],
            $char === '/' => ['name', $this->readName()],
            $char === '[' => $this->readArray(),
            $char === ']' || $char === '>' || $char === '}' || $char === ')' => $this->skipOne(),
            $char === '{' => $this->skipOne(),
            default => $this->readKeywordOrNumber(),
        };
    }

    /**
     * Consult the budget, and schedule the next look.
     *
     * Every loop that advances the offset compares it with `$checkAt` inline and calls this
     * when it is reached; the comparison is per byte, the call is per `BYTES_PER_TICK`.
     *
     * @throws PreflightBudgetException
     */
    private function charge(): void
    {
        $this->budget->tick();
        $this->checkAt = $this->offset + self::BYTES_PER_TICK;
    }

    /** @return array{string, mixed} */
    private function skipOne(): array
    {
        $this->offset++;

        return ['null', null];
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->offset < $this->length) {
            if ($this->offset >= $this->checkAt) {
                $this->charge();
            }

            $char = $this->data[$this->offset];
            if (str_contains(self::WHITESPACE, $char)) {
                $this->offset++;

                continue;
            }

            if ($char === '%') {
                while ($this->offset < $this->length && $this->data[$this->offset] !== "\n" && $this->data[$this->offset] !== "\r") {
                    if ($this->offset >= $this->checkAt) {
                        $this->charge();
                    }
                    $this->offset++;
                }

                continue;
            }

            return;
        }
    }

    private function readName(): string
    {
        $this->offset++;
        $start = $this->offset;
        while ($this->offset < $this->length) {
            if ($this->offset >= $this->checkAt) {
                $this->charge();
            }
            $char = $this->data[$this->offset];
            if (str_contains(self::WHITESPACE, $char) || str_contains(self::DELIMITERS, $char)) {
                break;
            }
            $this->offset++;
        }

        $raw = substr($this->data, $start, $this->offset - $start);

        return str_contains($raw, '#')
            ? (string) preg_replace_callback(
                '/#([0-9A-Fa-f]{2})/',
                static fn (array $m): string => chr((int) hexdec($m[1])),
                $raw,
            )
            : $raw;
    }

    /** Returns the decoded bytes of a literal string, escapes applied. */
    private function readLiteralString(): string
    {
        $this->offset++;
        $depth = 1;
        $out = '';
        while ($this->offset < $this->length) {
            // Also bounds the decoded copy: it grows at most one byte per byte advanced over, so
            // the memory backstop sees it growing rather than once it is whole.
            if ($this->offset >= $this->checkAt) {
                $this->charge();
            }
            $char = $this->data[$this->offset++];

            if ($char === '\\') {
                $out .= $this->readEscape();

                continue;
            }

            if ($char === '(') {
                $depth++;
                $out .= $char;

                continue;
            }

            if ($char === ')') {
                if (--$depth === 0) {
                    return $out;
                }
                $out .= $char;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    private function readEscape(): string
    {
        if ($this->offset >= $this->length) {
            return '';
        }

        $char = $this->data[$this->offset++];

        return match ($char) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\x0C",
            '(' => '(',
            ')' => ')',
            '\\' => '\\',
            "\n" => '',
            "\r" => $this->consumeIf("\n"),
            default => $this->readOctalEscape($char),
        };
    }

    private function consumeIf(string $expected): string
    {
        if (($this->data[$this->offset] ?? '') === $expected) {
            $this->offset++;
        }

        return '';
    }

    private function readOctalEscape(string $first): string
    {
        if ($first < '0' || $first > '7') {
            return $first;
        }

        $digits = $first;
        while (strlen($digits) < 3) {
            $next = $this->data[$this->offset] ?? '';
            if ($next < '0' || $next > '7' || $next === '') {
                break;
            }
            $digits .= $next;
            $this->offset++;
        }

        return chr(octdec($digits) & 0xFF);
    }

    /** Returns the raw bytes decoded from a hexadecimal string. */
    private function readHexString(): string
    {
        $this->offset++;
        $hex = '';
        while ($this->offset < $this->length) {
            if ($this->offset >= $this->checkAt) {
                $this->charge();
            }
            $char = $this->data[$this->offset++];
            if ($char === '>') {
                break;
            }
            if (ctype_xdigit($char)) {
                $hex .= $char;
            }
        }

        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return (string) hex2bin($hex);
    }

    /** @return array{string, array<int, array{string, mixed}>} */
    private function readArray(): array
    {
        $this->offset++;
        $items = [];
        while ($this->offset < $this->length) {
            $this->skipWhitespaceAndComments();
            if (($this->data[$this->offset] ?? '') === ']') {
                $this->offset++;
                break;
            }

            $token = $this->nextToken();
            if ($token === null) {
                break;
            }

            if (count($items) < 4096) {
                $items[] = $token;
            }
        }

        return ['array', $items];
    }

    /** @return array{string, array<int, array{string, mixed}>} */
    private function readDictionary(): array
    {
        $this->offset += 2;
        $items = [];
        while ($this->offset < $this->length) {
            $this->skipWhitespaceAndComments();
            if (substr($this->data, $this->offset, 2) === '>>') {
                $this->offset += 2;
                break;
            }

            $token = $this->nextToken();
            if ($token === null) {
                break;
            }

            if (count($items) < 1024) {
                $items[] = $token;
            }
        }

        return ['dict', $items];
    }

    /** @return array{string, mixed} */
    private function readKeywordOrNumber(): array
    {
        $start = $this->offset;
        while ($this->offset < $this->length) {
            if ($this->offset >= $this->checkAt) {
                $this->charge();
            }
            $char = $this->data[$this->offset];
            if (str_contains(self::WHITESPACE, $char) || str_contains(self::DELIMITERS, $char)) {
                break;
            }
            $this->offset++;
        }

        if ($this->offset === $start) {
            $this->offset++;

            return ['null', null];
        }

        $word = substr($this->data, $start, $this->offset - $start);

        if (is_numeric($word)) {
            return ['num', (float) $word];
        }

        return match ($word) {
            'true' => ['bool', true],
            'false' => ['bool', false],
            'null' => ['null', null],
            default => ['operator', $word],
        };
    }

    /**
     * Inline images (BI ... ID <binary> EI) carry raw bytes that are not lexable, so the
     * whole run is skipped rather than being tokenised as garbage operators.
     */
    private function skipInlineImage(): void
    {
        $idPos = strpos($this->data, 'ID', $this->offset);
        if ($idPos === false) {
            $this->offset = $this->length;

            return;
        }

        $search = $idPos + 3;
        while (($eiPos = strpos($this->data, 'EI', $search)) !== false) {
            $before = $this->data[$eiPos - 1] ?? '';
            $after = $this->data[$eiPos + 2] ?? ' ';
            if (str_contains(self::WHITESPACE, $before) && (str_contains(self::WHITESPACE, $after) || str_contains(self::DELIMITERS, $after))) {
                $this->offset = $eiPos + 2;

                return;
            }

            // An image of nothing but candidate end markers costs one pass of this loop per two
            // bytes, so the search is charged like any other scan.
            $this->offset = $search = $eiPos + 2;
            if ($this->offset >= $this->checkAt) {
                $this->charge();
            }
        }

        $this->offset = $this->length;
    }
}
