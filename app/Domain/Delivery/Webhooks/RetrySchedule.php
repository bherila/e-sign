<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

/**
 * How long to wait before the next attempt.
 *
 * The delays are configuration, not a constant: the profile documents
 * immediate, +5 min, +1 h and three attempts total, and ours is longer and
 * configurable, with the difference recorded in the capability matrix rather
 * than hidden. The default schedule is 1 m, 5 m, 30 m, 2 h, 12 h, 24 h — seven
 * attempts spanning roughly 40 hours, enough to ride out a receiver's overnight
 * outage without hammering it.
 *
 * Jitter matters more than it looks: without it, an outage that fails every
 * endpoint at once produces a retry stampede at exactly the same instant, which
 * is how a recovering receiver gets knocked over a second time.
 */
final readonly class RetrySchedule
{
    /**
     * @param  list<int>  $delays  Seconds to wait after attempt 1, 2, … in order.
     * @param  float  $jitter  Fraction of each delay applied as +/- randomness.
     */
    public function __construct(
        private array $delays,
        private float $jitter = 0.1,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `esign.delivery.webhooks` array.
     */
    public static function fromConfig(array $config): self
    {
        $delays = $config['retry_delays'] ?? [];
        $delays = is_array($delays) ? array_values(array_map('intval', $delays)) : [];

        return new self(
            delays: array_values(array_filter($delays, static fn (int $seconds): bool => $seconds > 0)),
            jitter: max(0.0, min(1.0, (float) ($config['retry_jitter'] ?? 0.1))),
        );
    }

    public function maxAttempts(): int
    {
        return count($this->delays) + 1;
    }

    /**
     * Seconds to wait after `$attempt` failed, or null when `$attempt` was the
     * last one the schedule allows.
     */
    public function delayAfterAttempt(int $attempt): ?int
    {
        $base = $this->delays[$attempt - 1] ?? null;

        if ($base === null) {
            return null;
        }

        if ($this->jitter <= 0.0) {
            return $base;
        }

        $spread = (int) round($base * $this->jitter);

        return max(1, $base + random_int(-$spread, $spread));
    }
}
