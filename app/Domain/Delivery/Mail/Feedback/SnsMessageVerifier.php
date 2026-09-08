<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

/**
 * Proves that an SNS envelope really came from AWS.
 *
 * A port with one implementation shipped (RejectingSnsMessageVerifier, which refuses
 * everything) so that the SES feedback path can exist, be reviewed, and be tested without
 * an unverified HTTP body ever being able to change a mail row.
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
