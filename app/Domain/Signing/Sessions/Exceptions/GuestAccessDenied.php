<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions\Exceptions;

use App\Domain\Signing\Exceptions\SigningException;

/**
 * A guest presented something that does not authorize what they asked for.
 *
 * One exception with a stable `reason`, rather than a class per cause, because the *reason*
 * is for the audit trail and for tests, and the guest is deliberately told much less than it
 * contains. Distinguishing "there is no such invitation" from "there is one and it expired"
 * on screen would turn the landing page into an oracle for which recipient identifiers and
 * which tokens exist; the page says the link cannot be used and offers the one useful next
 * step, which is to ask the sender for another.
 *
 * The token is never part of the message. Nothing in this class, and nothing that catches
 * it, may put a credential into a log line (docs/HANDOFF.md section 8).
 */
final class GuestAccessDenied extends SigningException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** No row matched the presented verifier, or the URL's envelope is not the token's. */
    public static function unknownInvitation(): self
    {
        return new self('The invitation credential presented does not match a live invitation.', 'unknown_invitation');
    }

    /** A row matched, and it is revoked, already consumed, or out of time. */
    public static function invitationNotLive(string $reason): self
    {
        return new self('The invitation credential presented is no longer usable.', 'invitation_'.$reason);
    }

    /** The recipient exists but is not the one who may act right now. */
    public static function recipientNotActive(string $state): self
    {
        return new self('This recipient is not currently eligible to sign.', 'recipient_'.$state);
    }

    /** The envelope is cancelled, declined, expired, or already past signing. */
    public static function envelopeNotSignable(string $state): self
    {
        return new self('This agreement is no longer open for signature.', 'envelope_'.$state);
    }

    /** No signing cookie, or one that matches no live session. */
    public static function noSession(): self
    {
        return new self('This request carried no live signing session.', 'no_session');
    }

    /** A live session, for a different envelope or a different recipient than the URL names. */
    public static function sessionOutOfScope(): self
    {
        return new self('This signing session does not cover the agreement in this URL.', 'session_out_of_scope');
    }

    public function code(): string
    {
        return 'guest_access_denied';
    }
}
