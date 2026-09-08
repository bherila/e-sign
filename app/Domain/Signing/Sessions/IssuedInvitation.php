<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Signing\Sessions\Models\RecipientInvitation;
use Carbon\CarbonImmutable;

/**
 * The one moment an invitation token exists outside a browser.
 *
 * `InvitationIssuer` returns this, the caller mails `$url`, and the object goes out of
 * scope. There is no way to get the token back afterwards: only its SHA-256 verifier was
 * stored, so a resend is a *reissue* — a new credential that revokes this one — rather than
 * a second copy of the same secret. That is the rotation story docs/HANDOFF.md section 8
 * asks to be explicit about.
 *
 * The token is deliberately not exposed as a bare property alongside the URL. A caller
 * wanting to build its own link would be building a second definition of the signing URL;
 * `$url` is already absolute and already correct.
 *
 * Nothing here is `Stringable` and nothing overrides `__debugInfo`, which means a
 * `dd($issued)` in development *would* show the URL. That is the right trade for a value
 * that lives for one function call — and it is why `$url` is never passed to a logger, an
 * exception message, or an audit payload anywhere in this module.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public RecipientInvitation $invitation,
        /** Absolute, credential-bearing, and safe to put in exactly one mail. */
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
