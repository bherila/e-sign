<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

/**
 * The outcome of a single readiness probe. The message is a short, human
 * sentence only: never a secret, hostname, DSN, filesystem path, or document
 * name. See docs/operations/health.md for the contract every probe must
 * honor.
 */
final class ProbeResult
{
    public function __construct(
        public readonly string $name,
        public readonly HealthStatus $status,
        public readonly string $message,
    ) {}

    public static function ok(string $name, string $message): self
    {
        return new self($name, HealthStatus::Ok, $message);
    }

    public static function warn(string $name, string $message): self
    {
        return new self($name, HealthStatus::Warn, $message);
    }

    public static function fail(string $name, string $message): self
    {
        return new self($name, HealthStatus::Fail, $message);
    }

    /**
     * @return array{status: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
        ];
    }
}
