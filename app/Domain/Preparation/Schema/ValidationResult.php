<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Schema;

/**
 * The outcome of validating a field document: every problem found, not just the first.
 *
 * The editor needs the whole list to annotate every bad field in one pass, so the validator
 * never stops at the first error. Errors are in document order.
 */
final readonly class ValidationResult
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(public array $errors = []) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (ValidationError $error): string => $error->code->value, $this->errors);
    }

    public function hasCode(ValidationCode $code): bool
    {
        foreach ($this->errors as $error) {
            if ($error->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Errors reported at exactly this JSON Pointer.
     *
     * @return list<ValidationError>
     */
    public function at(string $path): array
    {
        return array_values(array_filter(
            $this->errors,
            static fn (ValidationError $error): bool => $error->path === $path,
        ));
    }

    public function first(): ?ValidationError
    {
        return $this->errors[0] ?? null;
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ValidationError $error): array => $error->toArray(), $this->errors);
    }

    public function describe(): string
    {
        return implode('; ', array_map(
            static fn (ValidationError $error): string => $error->describe(),
            $this->errors,
        ));
    }
}
