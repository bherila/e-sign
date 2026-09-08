<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Preflight;

/**
 * One reason a document was rejected, or one caveat about how it will be handled.
 *
 * `message` is written for the person who uploaded the file: it says what is wrong
 * and what they can do about it, never just a code.
 */
final readonly class PreflightFinding
{
    public function __construct(
        public PreflightCode $code,
        public PreflightSeverity $severity,
        public string $message,
        public ?int $page = null,
    ) {}

    public static function reject(PreflightCode $code, string $message, ?int $page = null): self
    {
        return new self($code, PreflightSeverity::Reject, $message, $page);
    }

    public static function warn(PreflightCode $code, string $message, ?int $page = null): self
    {
        return new self($code, PreflightSeverity::Warning, $message, $page);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'page' => $this->page,
        ];
    }
}
