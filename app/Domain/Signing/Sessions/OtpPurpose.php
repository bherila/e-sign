<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

/**
 * Which flow asked for a one-time code.
 *
 * Two flows send codes to the same mailbox, and a code issued for one must not be
 * presentable to the other. The legacy resolver's code stands in for the invitation
 * credential the caller does not have; the session-start code is an extra check on top of a
 * credential they already presented. Letting them be interchangeable would make the weaker
 * of the two the effective bar for both.
 */
enum OtpPurpose: string
{
    /** Exchanging an invitation credential for a session, when OTP is required. */
    case SessionStart = 'session_start';

    /** The compatibility `/signing/{recipientPublicId}` resolver, which has no credential. */
    case LegacyResolve = 'legacy_resolve';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
