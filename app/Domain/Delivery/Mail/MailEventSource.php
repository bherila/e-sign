<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

/**
 * Who is asserting an event. Kept on every row because the difference between "we handed
 * this to a transport" and "a provider says a mailbox took it" is the difference between
 * `sent_to_provider` and `delivered`, and an operator reading the event log needs to see
 * which one they are looking at.
 */
enum MailEventSource: string
{
    case App = 'app';
    case Brevo = 'brevo';
    case Ses = 'ses';

    /** True where the source can legitimately claim a mailbox accepted the message. */
    public function canReportDelivery(): bool
    {
        return $this !== self::App;
    }
}
