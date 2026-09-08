<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

/**
 * The outcome of one delivery attempt row.
 *
 * A row is one attempt, so these describe an attempt and not the event.
 * `failed` and `exhausted` are deliberately distinct: `exhausted` means the
 * last attempt in the configured schedule failed, while `failed` means this
 * attempt failed and whether anything follows is read from `next_attempt_at` —
 * set when a retry is scheduled, null when the response said retrying cannot
 * help (a 4xx other than 408 and 429, or a destination the policy refuses).
 * Telling those apart is the difference between "fix the endpoint" and "the
 * receiver was down for two days".
 */
enum DeliveryState: string
{
    /** Created and due at `next_attempt_at`; no attempt has been made yet. */
    case Pending = 'pending';

    /** The receiver answered 2xx. */
    case Succeeded = 'succeeded';

    /** The attempt failed; `next_attempt_at` says whether a retry follows. */
    case Failed = 'failed';

    /** The last attempt in the configured schedule failed. */
    case Exhausted = 'exhausted';

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
