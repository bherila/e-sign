<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Signing\Sessions\Exceptions\GuestRateLimited;
use Illuminate\Cache\RateLimiter;

/**
 * Every ceiling the guest surface applies, in one place and with one shape.
 *
 * Laravel's `RateLimiter` is the mechanism; this is the vocabulary. Having one class means
 * the key prefix, the window, and the "hit it, then throw" ordering are decided once —
 * three details that are individually trivial and collectively the difference between a
 * limit that works and one that resets itself on every request.
 *
 * Keys are always digests of the thing being limited, never the thing itself. A cache store
 * is shared infrastructure and often a different process's memory; a key of
 * `signing:otp:someone@example.test` would put a recipient's address in it.
 *
 * A refused attempt is *not* recorded as an attempt. Otherwise a client that keeps trying
 * after being told to stop extends its own penalty indefinitely, which sounds appealing and
 * in practice locks out the signer whose office shares an egress address with them.
 */
final class GuestThrottle
{
    /** One hour, for every limiter here; the configured numbers are all "per hour". */
    public const WINDOW_SECONDS = 3_600;

    private const PREFIX = 'esign:signing:';

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Count one attempt against a named ceiling, or refuse.
     *
     * @param  string  $limiter  Stable name for the trail: `otp_issue_per_address`, and so on.
     * @param  string  $key  Already a digest. See the class docblock.
     *
     * @throws GuestRateLimited
     */
    public function hit(string $limiter, string $key, int $maxPerHour): void
    {
        $cacheKey = self::PREFIX.$limiter.':'.$key;
        $max = max(1, $maxPerHour);

        if ($this->limiter->tooManyAttempts($cacheKey, $max)) {
            throw new GuestRateLimited($limiter, $this->limiter->availableIn($cacheKey));
        }

        $this->limiter->hit($cacheKey, self::WINDOW_SECONDS);
    }

    /** Forget a limiter's count. Used after a success that proves the client is legitimate. */
    public function clear(string $limiter, string $key): void
    {
        $this->limiter->clear(self::PREFIX.$limiter.':'.$key);
    }
}
