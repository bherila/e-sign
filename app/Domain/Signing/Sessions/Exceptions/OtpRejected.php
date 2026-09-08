<?php

declare(strict_types=1);

namespace App\Domain\Signing\Sessions\Exceptions;

use App\Domain\Signing\Exceptions\SigningException;

/**
 * A mailed code was not accepted.
 *
 * `attemptsRemaining` is reported to the person because withholding it does not slow an
 * attacker — the ceiling is fixed and public in the documentation — and it does stop a
 * legitimate signer with a typo from burning a challenge they could still have used.
 *
 * The code itself never appears in the message, in a log line, or in an audit payload.
 */
final class OtpRejected extends SigningException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $attemptsRemaining,
    ) {
        parent::__construct($message);
    }

    public static function wrongCode(int $attemptsRemaining): self
    {
        return new self('That code is not the one that was sent.', 'wrong_code', $attemptsRemaining);
    }

    public static function noOpenChallenge(): self
    {
        return new self('There is no code outstanding for this agreement; request a new one.', 'no_open_challenge', 0);
    }

    public static function expired(): self
    {
        return new self('That code has expired; request a new one.', 'expired', 0);
    }

    public static function exhausted(): self
    {
        return new self('Too many incorrect codes were entered; request a new one.', 'exhausted', 0);
    }

    public function code(): string
    {
        return 'otp_rejected';
    }
}
