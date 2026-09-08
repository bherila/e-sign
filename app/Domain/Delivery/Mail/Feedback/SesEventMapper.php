<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailState;

/**
 * SES notification types to this application's states.
 *
 * SES publishes these through SNS, so the type here is the `notificationType` (or
 * `eventType`, depending on which SES feature is configured) inside the SNS `Message`
 * body, not the SNS envelope's own `Type`.
 *
 * As with Brevo, an unrecognized type is recorded and moves nothing, and a delay is not a
 * bounce.
 */
final class SesEventMapper
{
    /**
     * Null means "record the event, change nothing".
     */
    public function map(string $notificationType): ?MailState
    {
        return match (strtolower(trim($notificationType))) {
            // SES accepted the message for sending. Not delivery.
            'send' => MailState::Accepted,

            'delivery' => MailState::Delivered,

            /*
             * Permanent and Transient alike; see bounceClassification() for the reasoning
             * and for where the distinction is kept.
             */
            'bounce' => MailState::Bounced,

            // SES refused to send it at all (usually a virus or a suppression-list match).
            // No mail arrived, which is what `bounced` records.
            'reject' => MailState::Bounced,
            'rendering failure', 'renderingfailure' => MailState::Bounced,

            'complaint' => MailState::Complained,

            // Temporary; SES is still trying.
            'deliverydelay' => null,

            default => null,
        };
    }

    /**
     * How SES classified a bounce, for the event payload.
     *
     * SES calls a bounce `Permanent` (the address does not exist, the domain does not accept
     * mail, the recipient is on the account suppression list), `Transient` (a full mailbox, a
     * message too large, a receiving server throttling or briefly down), or `Undetermined`.
     *
     * **All three map to `bounced`, and that is a decision rather than an oversight.** The
     * state means "it did not arrive", which is true of every one of them; `MailState`'s own
     * definition says so explicitly ("hard or soft"), and Brevo's `softBounce` already maps
     * the same way, so switching providers does not silently change what an operator sees.
     *
     * Ranking is the reason not to invent a softer state. Ordering is decided by
     * `MailState::supersedes()` and `bounced` outranks `delivered`, so anything weaker would
     * have to sit *below* `delivered` to remain correctable — and a transient bounce on a
     * message that then never arrived would be invisible, which is the exact failure the
     * outbox exists to prevent.
     *
     * What the distinction is worth is diagnosis, so it is recorded here rather than
     * discarded: an operator can tell a full mailbox from an address that does not exist, and
     * only one of those is worth chasing.
     *
     * Null when this is not a bounce, or when SES did not classify it.
     *
     * @param  array<string, mixed>  $message  The decoded SES notification.
     * @return array{bounce_type: string|null, bounce_subtype: string|null}|null
     */
    public function bounceClassification(array $message): ?array
    {
        $bounce = $message['bounce'] ?? null;

        if (! is_array($bounce)) {
            return null;
        }

        $type = $bounce['bounceType'] ?? null;
        $subtype = $bounce['bounceSubType'] ?? null;

        if (! is_string($type) && ! is_string($subtype)) {
            return null;
        }

        return [
            'bounce_type' => is_string($type) ? substr(trim($type), 0, 64) : null,
            'bounce_subtype' => is_string($subtype) ? substr(trim($subtype), 0, 64) : null,
        ];
    }
}
