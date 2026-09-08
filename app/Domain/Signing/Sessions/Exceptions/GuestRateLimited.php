<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions\Exceptions;

use App\Domain\Signing\Exceptions\SigningException;

/**
 * A guest action was refused because it has been attempted too often.
 *
 * Separate from {@see GuestAccessDenied} because it is not a statement about the credential
 * at all — the same request may well succeed later — and because the answer carries a
 * `Retry-After`, which a denial must not.
 *
 * `limiter` names which ceiling was hit so the audit trail can distinguish "this mailbox is
 * being mailed too often" from "this client address is trying too many agreements". It is
 * not shown to the guest: telling a prober which of the two limits it tripped tells it how
 * to spread its next attempt.
 */
final class GuestRateLimited extends SigningException
{
    public function __construct(
        public readonly string $limiter,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('This action has been attempted too many times; wait before trying again.');
    }

    public function code(): string
    {
        return 'guest_rate_limited';
    }
}
