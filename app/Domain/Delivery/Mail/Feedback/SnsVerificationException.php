<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use RuntimeException;

/**
 * An SNS message could not be proven to have come from AWS, or came from somewhere this
 * deployment does not accept.
 *
 * Raised both when a signature is invalid and when no verifier is available at all. Those
 * are the same outcome on purpose: an endpoint that mutates mail state must not distinguish
 * "your signature is wrong" from "we are not checking signatures today", because the second
 * answer is an invitation.
 *
 * `$reason` is a short machine token — never the message text — and it exists so a refusal
 * can be *recorded* and counted without the recorded row quoting an attacker-supplied body
 * back into the database. It is never returned to the caller.
 */
class SnsVerificationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'unverified')
    {
        parent::__construct($message);
    }
}
