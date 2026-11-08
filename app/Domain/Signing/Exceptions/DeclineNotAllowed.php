<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

use App\Domain\Signing\Envelopes\SignerButtons;
use App\Domain\Signing\Models\Envelope;

/**
 * A decline on an agreement whose signers are not offered one (issue #165).
 *
 * `allow_decline` resolved false for this envelope ({@see SignerButtons}).
 * Hiding the button is not the rule; this is. The agreement can still be stopped — by its
 * sender cancelling it — but not by a recipient declining it, whichever surface asks.
 */
final class DeclineNotAllowed extends SigningException
{
    public function __construct(public readonly string $envelopeId)
    {
        parent::__construct(
            'Agreement "'.$envelopeId.'" does not allow its signers to decline. Only the sender can stop it, by cancelling it.',
        );
    }

    public static function on(Envelope $envelope): self
    {
        return new self($envelope->public_id);
    }

    public function code(): string
    {
        return 'decline_not_allowed';
    }
}
