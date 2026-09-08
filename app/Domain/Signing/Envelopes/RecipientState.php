<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

/**
 * Where one recipient stands, modelled independently of the envelope's own state
 * (docs/HANDOFF.md section 6).
 *
 * Only an `active` recipient may submit values or accept. Being `pending` is the ordering
 * guard in sequential mode: a later signer's link resolves, and does nothing, until the
 * stage before theirs finishes (docs/ARCHITECTURE.md invariant 1).
 */
enum RecipientState: string
{
    /** Invited but not yet eligible: an earlier signing stage is still open. */
    case Pending = 'pending';

    /** Eligible now. */
    case Active = 'active';

    /** Has attested. Their attestation is the record; this flag is only an index into it. */
    case Signed = 'signed';

    /** Refused. Declining one recipient declines the envelope. */
    case Declined = 'declined';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
