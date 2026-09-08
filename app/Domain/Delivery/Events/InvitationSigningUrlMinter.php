<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Events\Exceptions\SigningUrlUnavailable;
use App\Domain\Signing\Models\EnvelopeRecipient;
use App\Domain\Signing\Sessions\InvitationIssuer;
use Throwable;

/**
 * Mints a recipient's signing link by issuing a fresh one-shot invitation.
 *
 * Every call revokes the recipient's previous live invitation, which is what a reminder
 * needs: the earlier link is dead and the message carries a working one. The URL comes
 * from the issuer, so there is exactly one definition of what a signing link looks like.
 */
final readonly class InvitationSigningUrlMinter implements SigningUrlMinter
{
    public function __construct(private InvitationIssuer $invitations) {}

    public function signingUrlFor(EnvelopeRecipient $recipient): string
    {
        try {
            return $this->invitations->issue($recipient)->url;
        } catch (Throwable $e) {
            throw new SigningUrlUnavailable(
                'Could not issue a signing invitation for recipient '.$recipient->public_id.': '.$e->getMessage(),
                previous: $e,
            );
        }
    }
}
