<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

/**
 * What the outbox looks like right now, for the health probe and the backlog
 * command, which must not disagree with each other.
 */
final readonly class BacklogSnapshot
{
    public function __construct(
        /** Attempts that are due and have not run: the real backlog. */
        public int $overdue,
        /** Age in seconds of the oldest overdue attempt, or null if there is none. */
        public ?int $oldestOverdueSeconds,
        /** Retries waiting for a future time. Not a backlog; the schedule working. */
        public int $scheduled,
        public int $succeededLast24h,
        public int $exhaustedLast24h,
        public int $failedLast24h,
        public int $disabledEndpoints,
    ) {}
}
