<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailState;

/**
 * Brevo transactional event names to this application's states.
 *
 * Three things are deliberate here.
 *
 * An unmapped event returns null and is recorded without moving the state. Brevo adds
 * event names, and guessing what a new one means would let an unknown string decide whether
 * an agreement's invitation counts as delivered. Recorded-and-inert is the fail-closed
 * answer.
 *
 * `deferred` moves nothing. A deferral is the receiving server asking for a retry; the
 * message is still in flight and calling it bounced would send an operator chasing a
 * delivery that is about to succeed on its own.
 *
 * Engagement events are dropped entirely rather than recorded. This product does not
 * measure whether a recipient opened a message — the templates carry no tracking pixel by
 * design — and storing an `opened` row would reintroduce exactly that data through the
 * provider's side door.
 */
final class BrevoEventMapper
{
    /**
     * Engagement events: acknowledged with a 200 and then discarded, unstored.
     */
    private const IGNORED_EVENTS = [
        'opened',
        'unique_opened',
        'proxy_open',
        'click',
        'unique_click',
        'list_addition',
    ];

    public function isIgnored(string $event): bool
    {
        return in_array(strtolower(trim($event)), self::IGNORED_EVENTS, true);
    }

    /**
     * Null means "record the event, change nothing".
     */
    public function map(string $event): ?MailState
    {
        return match (strtolower(trim($event))) {
            // Brevo has taken ownership of the message. Stronger than our
            // `sent_to_provider`, which only says a transport accepted the bytes.
            'request' => MailState::Accepted,

            'delivered' => MailState::Delivered,

            // Hard and soft bounces are the same fact for this product — it did not arrive —
            // and the provider payload retains which one it was.
            'hardbounce', 'hard_bounce' => MailState::Bounced,
            'softbounce', 'soft_bounce' => MailState::Bounced,

            // Suppressed by Brevo (blocklist, prior bounce, invalid address) or failed
            // inside Brevo after it accepted the message. From the recipient's point of
            // view all of these are "no mail arrived", which is what `bounced` records.
            'blocked' => MailState::Bounced,
            'invalid_email' => MailState::Bounced,
            'error' => MailState::Bounced,

            // It arrived and the recipient reported it.
            'spam' => MailState::Complained,

            // Recorded, but it moves nothing. An unsubscribe is not a spam complaint and
            // not evidence about delivery — this application has no suppression state to
            // put it in, and `complained` is both the wrong claim (MailState defines it as
            // "the recipient marked it as spam") and the top rank, so nothing could ever
            // correct it afterwards.
            'unsubscribed' => null,

            // Still in flight; the receiving server asked for a retry.
            'deferred' => null,

            default => null,
        };
    }
}
