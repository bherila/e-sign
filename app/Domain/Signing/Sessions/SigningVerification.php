<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions;

use App\Domain\Signing\Envelopes\VerificationMethod;

/**
 * How a guest session was established.
 *
 * Two vocabularies exist on purpose and this is the only place they meet. This enum is what
 * the *access* module did — follow a link, and optionally answer a mailed code — and
 * {@see VerificationMethod} is what the *evidence* model records. Mapping them in one
 * method means a new access path has to state which evidence class it belongs to, rather
 * than a controller quietly writing a stronger-sounding string onto an attestation.
 *
 * Neither value is a claim about identity. Following a link demonstrates that somebody had
 * the mail; answering a code demonstrates continued access to the same mailbox. Both are
 * checks on one factor (docs/HANDOFF.md sections 8 and 9), and no combination of them makes
 * the resulting seal an eIDAS advanced or qualified human signature.
 */
enum SigningVerification: string
{
    /** The opaque invitation credential, and nothing else. */
    case Link = 'link';

    /** The credential plus a code mailed to the address the credential was sent to. */
    case LinkOtp = 'link+otp';

    public function attested(): VerificationMethod
    {
        return match ($this) {
            self::Link => VerificationMethod::EmailLink,
            self::LinkOtp => VerificationMethod::EmailOtp,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
