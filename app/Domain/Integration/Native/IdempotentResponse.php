<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

/**
 * A response recorded against an idempotency key, ready to be sent again unchanged.
 *
 * The body is the exact bytes that were sent the first time, not a re-serialisation of the
 * model: a replay that re-rendered the resource would drift from the original the moment the
 * envelope moved on, which defeats the point of replaying it at all.
 */
final readonly class IdempotentResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}
}
