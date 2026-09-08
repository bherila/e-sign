<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use RuntimeException;

/**
 * An SNS message could not be proven to have come from AWS.
 *
 * Raised both when a signature is invalid and when no verifier is available at all. Those
 * are the same outcome on purpose: an endpoint that mutates mail state must not distinguish
 * "your signature is wrong" from "we are not checking signatures today", because the second
 * answer is an invitation.
 */
class SnsVerificationException extends RuntimeException {}
