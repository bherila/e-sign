<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Events\Exceptions\SigningUrlUnavailable;
use App\Domain\Signing\Models\EnvelopeRecipient;

/**
 * Where an invitation's one link comes from.
 *
 * Mail knows who to tell and when. It does not know how a guest is admitted to a signing
 * page, how long that admission lasts, or how it is revoked — that is the guest-access
 * module's authority (`docs/HANDOFF.md` §7), and putting a second opinion about it in the
 * Delivery module would be a second way to grant access.
 *
 * The contract is narrow on purpose:
 *
 * - The URL is **absolute**, `https` outside local development, and carries at most one
 *   opaque query parameter. `App\Domain\Delivery\Mail\MailContext` enforces all three, so an
 *   implementation that returns something else is refused before a message is written.
 * - The URL **works when it is minted**. A recipient who is not eligible to sign yet still
 *   gets an invitation — plenty of deployments mail everyone at once, and the ordering guard
 *   lives in the domain rather than in whether a link resolves
 *   (`docs/signing/state-machine.md`).
 * - Failure is an **exception**, never a null or a placeholder. A message that reaches a
 *   signer with a dead link is worse than a message that was never sent: the signer has no
 *   way to tell the difference between "not my turn" and "broken", and the sender believes
 *   the agreement is in flight.
 */
interface SigningUrlMinter
{
    /**
     * @throws SigningUrlUnavailable When no link can be issued for this recipient.
     */
    public function signingUrlFor(EnvelopeRecipient $recipient): string;
}
