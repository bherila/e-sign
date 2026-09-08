<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Events\Exceptions\SigningUrlUnavailable;
use App\Domain\Signing\Models\EnvelopeRecipient;

/**
 * The default binding until guest access lands: it refuses, loudly.
 *
 * This is not a stub that will be forgotten. An implementation that returned a plausible
 * URL, or null, would let a deployment mail invitations whose links go nowhere and record
 * them as sent — the "successful no-op" AGENTS.md rules out, aimed at the one audience that
 * cannot report the problem, because an external signer has no account and no support path.
 *
 * Issue #36 binds `App\Domain\Signing\Sessions\InvitationIssuer` in its place.
 */
final class PlaceholderSigningUrlMinter implements SigningUrlMinter
{
    public function signingUrlFor(EnvelopeRecipient $recipient): string
    {
        throw new SigningUrlUnavailable(
            'No signing URL can be issued: nothing is bound to '.SigningUrlMinter::class.'. '
            .'Guest signing sessions (issue #36) mint these links; until that binding exists this '
            .'deployment refuses to send an invitation rather than send one that goes nowhere.'
        );
    }
}
