<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Contracts;

use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityDestinationException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityNotConfiguredException;
use App\Domain\Evidence\Sealing\Exceptions\TimestampAuthorityUnreachableException;

/**
 * Transport to a configured RFC 3161 timestamp authority.
 *
 * This port owns the HTTP exchange and the destination policy only. Building
 * the TimeStampReq and verifying the TimeStampResp against it (message
 * imprint, nonce, policy, genTime window, TSA signature and extended key
 * usage) belong to the signing library, which is why no method here takes or
 * returns anything but opaque DER.
 *
 * A self-hosted service is not an independent witness merely because it speaks
 * RFC 3161, and a cryptographically valid token is not a trusted one. This
 * port makes neither claim; it moves bytes and refuses bad destinations.
 */
interface TimestampAuthority
{
    /**
     * True when this deployment has a usable TSA endpoint configured.
     *
     * False means the deployment cannot produce PAdES B-T at all. It never
     * means B-T should quietly become B-B.
     */
    public function isConfigured(): bool;

    /**
     * The configured endpoint, for evidence and error messages.
     *
     * @throws TimestampAuthorityNotConfiguredException When nothing is configured.
     */
    public function endpoint(): string;

    /**
     * Assert the endpoint is configured and passes the destination policy.
     *
     * Runs no network request, so it is safe to call before inviting signers.
     *
     * @throws TimestampAuthorityNotConfiguredException
     * @throws TimestampAuthorityDestinationException
     */
    public function assertUsable(): void;

    /**
     * POST a DER TimeStampReq and return the DER TimeStampResp body.
     *
     * @param  string  $derRequest  DER-encoded RFC 3161 TimeStampReq.
     * @return string DER-encoded RFC 3161 TimeStampResp, unvalidated.
     *
     * @throws TimestampAuthorityNotConfiguredException
     * @throws TimestampAuthorityDestinationException
     * @throws TimestampAuthorityUnreachableException
     */
    public function post(string $derRequest): string;
}
