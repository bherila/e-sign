<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

use App\Domain\Evidence\Contracts\TimestampAuthority;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use RuntimeException;

/**
 * A timestamp authority that is not configured and fails loudly if anything tries to use it.
 *
 * The end-to-end suite seals at PAdES **B-B**, which needs no RFC 3161 round trip at all, and
 * the whole run is fenced by `Http::preventStrayRequests()`. This binding is the belt to that
 * fence: `HttpTimestampAuthority` reaches the network through Guzzle directly rather than
 * through the `Http` facade, so a stray-request guard would not see it. Refusing here means a
 * B-T request in this suite is an immediate, legible failure instead of a packet leaving the
 * process.
 *
 * It is not a "fake TSA that returns a token". There is no such thing that would be honest:
 * a token this process minted for itself is not an independent witness, and `docs/assurance.md`
 * is explicit that B-T rests on a third party. A test that wants B-T belongs in the sealing
 * suite, against a real authority, and skips when there is no egress.
 */
final class RefusingTimestampAuthority implements TimestampAuthority
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function endpoint(): string
    {
        throw new TimestampAuthorityNotConfiguredException(
            'The end-to-end suite configures no timestamp authority; it seals at B-B.',
        );
    }

    public function assertUsable(): void
    {
        throw new TimestampAuthorityNotConfiguredException(
            'The end-to-end suite configures no timestamp authority; it seals at B-B.',
        );
    }

    public function post(string $derRequest): string
    {
        throw new RuntimeException(
            'The end-to-end suite must not contact a timestamp authority: nothing in this run '
            .'is allowed to leave the process. Something asked for an assurance level above B-B.',
        );
    }
}
