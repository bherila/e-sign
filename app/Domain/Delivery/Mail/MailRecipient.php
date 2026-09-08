<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use InvalidArgumentException;

/**
 * Where one message is going. A plain value object rather than a model reference, because
 * the outbox mails people who have no account and may have no row anywhere: an external
 * signer, an operator alias, a sender whose membership was revoked after they sent the
 * envelope.
 *
 * The address is validated here so a malformed one is rejected at enqueue time, in the
 * caller's stack trace, rather than at 3am inside a queue worker.
 */
final class MailRecipient
{
    public readonly string $email;

    public readonly ?string $name;

    public function __construct(string $email, ?string $name = null)
    {
        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            // The address itself is not echoed: this message ends up in logs and in
            // exception reporters, and an address is the one thing in this class worth
            // keeping out of both.
            throw new InvalidArgumentException('An outbound mail recipient must have a valid email address.');
        }

        if (strlen($email) > 191) {
            throw new InvalidArgumentException('An outbound mail recipient address is longer than the column that stores it.');
        }

        $this->email = $email;

        $name = $name === null ? null : trim($name);
        $this->name = ($name === null || $name === '') ? null : $name;
    }
}
