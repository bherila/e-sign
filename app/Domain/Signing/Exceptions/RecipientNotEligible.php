<?php

declare(strict_types=1);

namespace App\Domain\Signing\Exceptions;

use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\EnvelopeRecipient;

/**
 * A recipient acted when it was not their turn, or after their turn ended.
 *
 * docs/ARCHITECTURE.md invariant 1: a recipient completes only their own fields, only while
 * eligible under the configured order. In sequential mode a later signer's invitation
 * resolves from the moment the envelope is sent — mail is delivered to everyone at once in
 * plenty of deployments — so the ordering guard has to live here, in the domain, and not in
 * whether a link works.
 */
final class RecipientNotEligible extends SigningException
{
    private function __construct(
        string $message,
        public readonly string $recipientId,
        public readonly string $state,
    ) {
        parent::__construct($message);
    }

    public static function notActive(EnvelopeRecipient $recipient, string $action): self
    {
        $state = $recipient->state;
        $explanation = $state === RecipientState::Pending
            ? 'an earlier signing stage has not finished'
            : 'they are '.$state->value;

        return new self(
            sprintf(
                'Recipient "%s" cannot %s: %s.',
                $recipient->schema_recipient_id,
                $action,
                $explanation,
            ),
            $recipient->schema_recipient_id,
            $state->value,
        );
    }

    public function code(): string
    {
        return 'recipient_not_eligible';
    }
}
