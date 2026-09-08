<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * To every party who was invited, plus the sender: the signing window closed.
 *
 * Deliberately not {@see CancelledMail}. Expiry is the expiry computed at send arriving
 * before everyone signed; nobody withdrew the agreement, and a message saying otherwise
 * would attribute a decision to a person who did not make one. The templates differ in
 * exactly that: this one names no actor and points at the deadline.
 *
 * No action link. An expired envelope has no page left to send anyone to, and a link that
 * resolves to a refusal is worse than the sentence explaining it.
 */
class ExpiredMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'agreementTitle'];
    }

    public function subjectLine(): string
    {
        return sprintf('"%s" expired before it was signed', $this->title());
    }

    protected function markdownView(): string
    {
        return 'mail.expired';
    }
}
