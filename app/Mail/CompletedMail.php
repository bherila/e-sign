<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * To every party: the agreement is executed.
 *
 * `$context->actionUrl` is a download URL, not an attachment. The executed PDF is not
 * attached, and that is a decision rather than an unfinished feature: mail is not a place
 * to put an executed instrument that has to remain retrievable, byte-identical, and
 * access-controlled for years, and a copy that escaped into a mailbox is a copy nobody can
 * account for. The link streams through the application, where the request is authorized
 * every time (docs/BLOB_STORAGE.md).
 *
 * The URL is optional. Completion is worth reporting even where this deployment has no
 * download surface configured for the recipient in question.
 */
class CompletedMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'agreementTitle'];
    }

    public function subjectLine(): string
    {
        return sprintf('"%s" is complete', $this->title());
    }

    protected function markdownView(): string
    {
        return 'mail.completed';
    }
}
