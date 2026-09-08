<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * Binds a field's initial value to a named variable, resolved when the envelope is sent.
 *
 * The variable name is preserved verbatim; this module does not resolve it. A name the sending
 * context cannot resolve is an error before send (`UNRESOLVED_PREFILL_VARIABLE`), never a
 * silently blank field.
 */
final readonly class Prefill
{
    public function __construct(public string $variable) {}

    /**
     * @param  array{variable: string}  $prefill
     */
    public static function fromArray(array $prefill): self
    {
        return new self($prefill['variable']);
    }

    /**
     * @return array{variable: string}
     */
    public function toArray(): array
    {
        return ['variable' => $this->variable];
    }
}
