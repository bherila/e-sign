<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Text;

/**
 * Which occurrence of an anchor string a field binds to.
 *
 * Three declared modes, no default:
 *  - `sole()`    the string must occur exactly once in scope; more than once is an error;
 *  - `index(n)`  the n-th occurrence in document order, 1-based;
 *  - `all()`     every occurrence, one placed box each.
 *
 * There is deliberately no "first match wins" fallback. An anchor that matches more
 * than once without saying which one it wants is under-specified, and silently taking
 * the first would move a signature box the moment the contract text changes.
 */
final readonly class AnchorOccurrence
{
    private const MODE_SOLE = 'sole';

    private const MODE_INDEX = 'index';

    private const MODE_ALL = 'all';

    private function __construct(private string $mode, public ?int $index) {}

    public static function sole(): self
    {
        return new self(self::MODE_SOLE, null);
    }

    public static function index(int $index): self
    {
        if ($index < 1) {
            throw new \InvalidArgumentException('Anchor occurrence indexes are 1-based.');
        }

        return new self(self::MODE_INDEX, $index);
    }

    public static function all(): self
    {
        return new self(self::MODE_ALL, null);
    }

    public function isSole(): bool
    {
        return $this->mode === self::MODE_SOLE;
    }

    public function isIndexed(): bool
    {
        return $this->mode === self::MODE_INDEX;
    }

    public function isAll(): bool
    {
        return $this->mode === self::MODE_ALL;
    }

    public function describe(): string
    {
        return $this->mode === self::MODE_INDEX ? 'index '.$this->index : $this->mode;
    }
}
