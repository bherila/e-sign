<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

/**
 * How the person giving assent was checked.
 *
 * docs/HANDOFF.md section 8 requires the evidence model to distinguish a trusted
 * application's identity assertion, control of an email address, and stronger
 * authentication. None of these is represented as proof of identity, and none of them makes
 * the resulting seal an eIDAS advanced or qualified human signature (AGENTS.md, "Honest
 * language").
 */
enum VerificationMethod: string
{
    /** Followed an opaque one-recipient invitation credential. Demonstrates mailbox access. */
    case EmailLink = 'email_link';

    /** Followed the link and additionally entered a one-time code sent to the same address. */
    case EmailOtp = 'email_otp';

    /** A trusted integrating application asserted the identity. Its assertion, not ours. */
    case TrustedAssertion = 'trusted_assertion';

    /** Signed in to this application as a workspace member. */
    case AuthenticatedUser = 'authenticated_user';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
