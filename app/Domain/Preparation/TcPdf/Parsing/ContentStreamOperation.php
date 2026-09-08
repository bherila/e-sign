<?php

declare(strict_types=1);

namespace App\Domain\Preparation\TcPdf\Parsing;

/**
 * One content-stream operator with the operands that preceded it.
 *
 * @phpstan-type Operand array{string, mixed}
 */
final readonly class ContentStreamOperation
{
    /** @param array<int, array{string, mixed}> $operands */
    public function __construct(public string $operator, public array $operands) {}

    /** Numeric operand at position $index, counting from the end when $fromEnd is true. */
    public function number(int $index, float $default = 0.0): float
    {
        $operand = $this->operands[$index] ?? null;

        return is_array($operand) && $operand[0] === 'num' && is_float($operand[1]) ? $operand[1] : $default;
    }

    /** @return array<int, float> The trailing $count numeric operands, or null when they are not all numbers. */
    public function trailingNumbers(int $count): ?array
    {
        $slice = array_slice($this->operands, -$count);
        if (count($slice) !== $count) {
            return null;
        }

        $numbers = [];
        foreach ($slice as $operand) {
            if (! is_array($operand) || $operand[0] !== 'num' || ! is_float($operand[1])) {
                return null;
            }
            $numbers[] = $operand[1];
        }

        return $numbers;
    }

    public function name(int $index): ?string
    {
        $operand = $this->operands[$index] ?? null;

        return is_array($operand) && $operand[0] === 'name' && is_string($operand[1]) ? $operand[1] : null;
    }

    /** Raw string bytes for a `(...)` or `<...>` operand. */
    public function stringBytes(int $index): ?string
    {
        $operand = $this->operands[$index] ?? null;
        if (! is_array($operand) || ! is_string($operand[1] ?? null)) {
            return null;
        }

        return in_array($operand[0], ['str', 'hex'], true) ? $operand[1] : null;
    }

    /** @return array<int, array{string, mixed}>|null */
    public function arrayOperand(int $index): ?array
    {
        $operand = $this->operands[$index] ?? null;
        if (is_array($operand) && $operand[0] === 'array' && is_array($operand[1])) {
            /** @var array<int, array{string, mixed}> $items */
            $items = $operand[1];

            return $items;
        }

        return null;
    }
}
