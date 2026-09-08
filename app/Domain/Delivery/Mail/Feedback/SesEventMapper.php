<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailState;

/**
 * SES notification types to this application's states.
 *
 * SES publishes these through SNS, so the type here is the `notificationType` (or
 * `eventType`, depending on which SES feature is configured) inside the SNS `Message`
 * body, not the SNS envelope's own `Type`.
 *
 * As with Brevo, an unrecognized type is recorded and moves nothing, and a delay is not a
 * bounce.
 */
final class SesEventMapper
{
    /**
     * Null means "record the event, change nothing".
     */
    public function map(string $notificationType): ?MailState
    {
        return match (strtolower(trim($notificationType))) {
            // SES accepted the message for sending. Not delivery.
            'send' => MailState::Accepted,

            'delivery' => MailState::Delivered,

            'bounce' => MailState::Bounced,

            // SES refused to send it at all (usually a virus or a suppression-list match).
            // No mail arrived, which is what `bounced` records.
            'reject' => MailState::Bounced,
            'rendering failure', 'renderingfailure' => MailState::Bounced,

            'complaint' => MailState::Complained,

            // Temporary; SES is still trying.
            'deliverydelay' => null,

            default => null,
        };
    }
}
