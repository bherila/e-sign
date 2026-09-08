<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A second (or third) nudge about an agreement that is still waiting.
 *
 * Deliberately close to the invitation rather than a distinct genre of message. A reminder
 * that reads as a new request makes a recipient wonder whether there are now two documents,
 * and a reminder that reads as a dunning notice makes them stop opening either. It carries
 * the same single link, because a reminder whose link differs from the invitation's is a
 * second live credential for the same act.
 */
class ReminderMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'senderName', 'agreementTitle', 'actionUrl'];
    }

    public function subjectLine(): string
    {
        return sprintf('Reminder: "%s" is waiting for your signature', $this->title());
    }

    protected function markdownView(): string
    {
        return 'mail.reminder';
    }
}
