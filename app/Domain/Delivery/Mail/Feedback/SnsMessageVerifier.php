<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

/**
 * Proves that an SNS envelope really came from AWS.
 *
 * Two implementations. AwsSnsMessageVerifier does the real work — AWS's canonical
 * string-to-sign, a certificate fetched through the destination policy and cached, a topic
 * allowlist, a replay window — and is bound whenever a topic is configured.
 * RejectingSnsMessageVerifier refuses everything and is bound when none is, so an
 * unconfigured deployment cannot let an unverified HTTP body change a mail row.
 *
 * A port rather than a concrete call site because the choice between those two is made in
 * the container, where a reader can see it, rather than inside a conditional on the request
 * path.
 */
interface SnsMessageVerifier
{
    /**
     * @param  array<string, mixed>  $envelope  The decoded SNS envelope.
     *
     * @throws SnsVerificationException When the message is not provably from AWS.
     */
    public function verify(array $envelope): void;
}
