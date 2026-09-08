<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

/**
 * One spelling of a Message-ID, so a stored value and a webhook value can be compared.
 *
 * The same identifier arrives in three shapes: Symfony's `SentMessage::getMessageId()`
 * returns it bare for API transports and bracketed for others, Brevo's webhook quotes it
 * with angle brackets, and SES reports its own `mail.messageId` with no brackets at all.
 * Matching feedback to a row means normalizing all three the same way, at the one place
 * that writes the column and the one place that reads it.
 *
 * Domains are case-insensitive and the local part of a Message-ID is generated, never typed,
 * so lowercasing loses nothing and makes a provider that shouts back still match.
 */
final class MessageId
{
    public static function normalize(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $normalized = strtolower(trim($messageId));
        $normalized = trim($normalized, '<>');
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        // The column is 191 characters. A Message-ID longer than that is not one this
        // application produced, and truncating it would create a false match.
        if (strlen($normalized) > 191) {
            return null;
        }

        return $normalized;
    }
}
