<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * "Someone would like you to sign this." The first thing an external recipient ever sees
 * from this deployment, and often the only one.
 *
 * The link is `$context->actionUrl`: an absolute URL that already carries whatever
 * authorization the recipient needs, minted by whatever created the message. This class
 * does not build it, sign it, or extend it — a template that could mint a signing
 * credential would be a template that has to be reviewed like one.
 *
 * A recipient's mail provider will fetch that link before the recipient does. That is fine
 * by design rather than by luck: GET on a signing page applies nothing and consumes
 * nothing (AGENTS.md, "GET is harmless"), so a scanner's preview cannot sign an agreement.
 */
class InvitationMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'senderName', 'agreementTitle', 'actionUrl'];
    }

    public function subjectLine(): string
    {
        return sprintf('%s asked you to sign "%s"', $this->subjectPart($this->context->senderName), $this->title());
    }

    protected function markdownView(): string
    {
        return 'mail.invitation';
    }
}
