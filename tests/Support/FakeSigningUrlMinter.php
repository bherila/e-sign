<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Events\Exceptions\SigningUrlUnavailable;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Signing\Models\EnvelopeRecipient;

/**
 * A signing-link minter for tests, standing in for the guest-access issuer.
 *
 * The URL it returns satisfies everything MailContext enforces — absolute, https, one opaque
 * query parameter, no fragment — because a fake that produced something the real validator
 * would reject would make the mail tests pass for the wrong reason.
 *
 * All hosts are `.test`, which RFC 6761 reserves and no resolver answers (AGENTS.md:
 * synthetic fixtures only).
 */
final class FakeSigningUrlMinter implements SigningUrlMinter
{
    /** @var list<string> Public ids of the recipients a URL was minted for, in order. */
    public array $minted = [];

    public function __construct(private readonly bool $refuses = false) {}

    public static function refusing(): self
    {
        return new self(refuses: true);
    }

    public function signingUrlFor(EnvelopeRecipient $recipient): string
    {
        if ($this->refuses) {
            throw new SigningUrlUnavailable('No signing session could be issued for this recipient.');
        }

        $this->minted[] = $recipient->public_id;

        return 'https://esign.example.test/sign/'.$recipient->public_id.'?t=c3ludGhldGljLXRva2Vu';
    }
}
