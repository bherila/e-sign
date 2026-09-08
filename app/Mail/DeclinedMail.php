<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * To the sender: a recipient declined.
 *
 * Declining is a legitimate outcome, not an error, and the copy says so. It names the party
 * who declined (`$context->actorName`) and passes their stated reason through verbatim when
 * there is one, because the sender's next move depends entirely on which it was — a wrong
 * signatory, a term they will not accept, or the wrong document altogether.
 *
 * There is no action link. A declined agreement has nothing for the sender to do inside a
 * mail client, and giving this message a credential-bearing URL would mean the one mail
 * most likely to be forwarded to a lawyer is also the one carrying a token.
 */
class DeclinedMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'agreementTitle', 'actorName'];
    }

    public function subjectLine(): string
    {
        return sprintf('%s declined to sign "%s"', $this->subjectPart($this->context->actorName), $this->title());
    }

    protected function markdownView(): string
    {
        return 'mail.declined';
    }
}
