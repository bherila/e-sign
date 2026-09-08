<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * To the recipients: the sender withdrew the agreement.
 *
 * Sent to everyone who was asked to sign, including those who already had, because a
 * recipient who signed and then hears nothing will reasonably assume the agreement stands.
 * It states plainly that any link they were sent no longer works, which is the question
 * this message exists to answer before someone clicks an old invitation and reads an
 * error page as a bug.
 */
class CancelledMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'agreementTitle', 'actorName'];
    }

    public function subjectLine(): string
    {
        return sprintf('"%s" was cancelled', $this->title());
    }

    protected function markdownView(): string
    {
        return 'mail.cancelled';
    }
}
