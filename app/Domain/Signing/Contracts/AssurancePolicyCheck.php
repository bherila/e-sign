<?php

declare(strict_types=1);

namespace App\Domain\Signing\Contracts;

use App\Domain\Evidence\Sealing\AssuranceLevel;

/**
 * Asks, before anyone is invited, whether this deployment can seal at the level the envelope
 * requires.
 *
 * docs/HANDOFF.md section 9 is explicit: "Reject absent, unusable, expired, or mismatched
 * configured signing material before inviting signers", and a level that cannot be met is an
 * error rather than a silent downgrade to the level below. Sending first and discovering at
 * finalization that there is no timestamp authority means people have already signed
 * something the service cannot produce.
 *
 * This is a *declaration* check, not a seal. It answers "is the material configured and
 * present", cheaply and without touching a private key or contacting a TSA. The real
 * cryptographic verification happens at seal time in the Evidence module and can still fail;
 * that is why a completion event is published only after a validated artifact exists.
 */
interface AssurancePolicyCheck
{
    /**
     * Null when the material for `$level` is declared available.
     *
     * Otherwise a short operator-facing reason, which the send gate reports verbatim. Never
     * return null on uncertainty: an unknown answer is an unavailable one.
     */
    public function unavailableReason(AssuranceLevel $level): ?string;
}
