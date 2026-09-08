<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailErrorRedactor;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\MessageId;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\Models\OutboundMailEvent;
use Illuminate\Support\Carbon;

/**
 * Applies one piece of provider feedback: finds the message it is about, records the claim,
 * and moves the state if the claim outranks what is already known.
 *
 * This is the only writer of `delivered`, `bounced`, and `complained`. The application
 * cannot reach those states on its own, which is the point: `sent_to_provider` is a
 * statement about a transport conversation, and only a provider can tell us what a mailbox
 * did afterwards.
 *
 * Matching is by Message-ID and nothing else. An address would be the obvious alternative
 * and is the wrong one — the same recipient can hold several open invitations, and marking
 * the wrong one bounced would tell a sender their counterparty is unreachable when the
 * message that failed was a different agreement's reminder.
 *
 * A Message-ID with no row is recorded as an orphan rather than dropped. Feedback about
 * messages this deployment never sent is normal (a restored database, a shared sending
 * domain, a webhook pointed at the wrong environment), and a silent 200 would make a
 * misconfigured provider indistinguishable from a quiet one.
 *
 * Out-of-order and duplicate webhooks are expected. Providers retry, and a `delivered` can
 * arrive after the `bounce` that superseded it, so ordering is decided by
 * MailState::supersedes() rather than by arrival time.
 */
final class MailFeedbackRecorder
{
    public function __construct(private readonly MailErrorRedactor $redactor) {}

    /**
     * @param  array<string, mixed>  $payload  The provider body; redacted here, not by the caller.
     */
    public function record(
        MailEventSource $source,
        string $event,
        ?string $messageId,
        ?MailState $state,
        array $payload = [],
        ?Carbon $occurredAt = null,
    ): OutboundMailEvent {
        $normalized = MessageId::normalize($messageId);
        $redacted = $this->redactor->payload($payload);
        $occurredAt ??= Carbon::now();

        $mail = $normalized === null
            ? null
            : OutboundMail::query()->forMessageId($normalized)->first();

        if ($mail === null) {
            return OutboundMailEvent::create([
                'outbound_mail_id' => null,
                'source' => $source,
                'event' => $event,
                'message_id' => $normalized,
                // Our own keys first: with `+` the left operand wins, so a provider body
                // that happens to carry an `orphan` field cannot overwrite our verdict on
                // whether this event matched anything.
                'payload' => ['orphan' => true] + $redacted,
                'occurred_at' => $occurredAt,
            ]);
        }

        $previous = $mail->state;
        $moved = $state !== null && $mail->applyFeedbackState($state, $occurredAt);

        // Same ordering rule as above, and it matters more here: these three fields are
        // what an operator reads to tell a duplicate webhook from one this application
        // chose to ignore, and a payload key of the same name must not be able to rewrite
        // them.
        return $mail->recordEvent($source, $event, [
            'state_before' => $previous->value,
            'state_after' => $mail->state->value,
            'state_changed' => $moved,
        ] + $redacted, $occurredAt);
    }
}
