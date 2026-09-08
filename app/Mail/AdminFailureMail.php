<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * To the operators: something that needs a human failed.
 *
 * The two cases this exists for are a finalization that could not produce a validated
 * sealed PDF, and a webhook endpoint that has been disabled after repeated failures.
 * Both are states where the product has correctly refused to pretend (AGENTS.md, "fail
 * closed") and where nothing further happens until somebody looks.
 *
 * `$context->failureSummary` is a sentence written by whatever detected the problem and
 * `$context->reference` is the identifier to look up. Neither is a stack trace: this
 * message goes to a mailbox, and a mailbox is not a log aggregator or an incident tracker.
 * There is no link, because an operator mail should not be a way to reach a privileged
 * surface without authenticating.
 */
class AdminFailureMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'failureSummary', 'reference'];
    }

    public function subjectLine(): string
    {
        return sprintf('[%s] Action needed: %s', $this->brand(), (string) $this->context->reference);
    }

    protected function markdownView(): string
    {
        return 'mail.admin-failure';
    }
}
