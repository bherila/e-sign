<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use RuntimeException;

/**
 * A refusal the native API can put on the wire as-is.
 *
 * Everything the client is told is on this object: an {@see ErrorCode}, which fixes the HTTP
 * status, a human message, and an optional `details` object. Nothing else is added by the
 * boundary that renders it, so a message written here is the message a caller reads and
 * there is no second place to look for the wording.
 *
 * Domain exceptions are *not* replaced by this one. App\Http\Middleware\Api\ApiErrorBoundary
 * translates the signing module's own refusals into this shape, so the rules stay in the
 * state machine (AGENTS.md, "one state machine") and only their presentation lives here.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function of(ErrorCode $code, string $message, ?array $details = null): self
    {
        return new self($code, $message, $details);
    }

    /** The one 404 the API returns, whatever the identifier named. */
    public static function notFound(string $what): self
    {
        return new self(
            ErrorCode::NotFound,
            'No '.$what.' with that id exists in this workspace.',
        );
    }

    public static function unsupported(string $message, ?array $details = null): self
    {
        return new self(ErrorCode::Unsupported, $message, $details);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    /**
     * @return array{error: array{code: string, message: string, details?: array<string, mixed>}}
     */
    public function toArray(): array
    {
        $error = [
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
        ];

        if ($this->details !== null && $this->details !== []) {
            $error['details'] = $this->details;
        }

        return ['error' => $error];
    }
}
