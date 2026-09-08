<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

/**
 * What is actually known about one outbound message.
 *
 * These are seven distinct facts, not a convenience gradient, and the distinctions are the
 * whole point of the outbox:
 *
 *   queued            The row exists. Nothing has been handed to a transport. This is not
 *                     "sent" and it is emphatically not "delivered".
 *   sent_to_provider  A transport accepted the bytes and returned a Message-ID. That is a
 *                     statement about a TCP conversation, not about a mailbox.
 *   accepted          The provider has since confirmed it took ownership of the message
 *                     (Brevo `request`). Still not a mailbox.
 *   delivered         A receiving mail server accepted final delivery, per provider
 *                     feedback. Only provider feedback can write this state.
 *   bounced           Delivery failed at the receiving end, hard or soft.
 *   complained        The recipient marked it as spam. The message arrived; the relationship
 *                     did not survive it.
 *   failed            This application gave up: every attempt raised a transport error.
 *
 * Signing never depends on any of these. An envelope is not "sent" because mail was
 * delivered, and it is not stuck because mail bounced; see docs/delivery/mail.md.
 */
enum MailState: string
{
    case Queued = 'queued';
    case SentToProvider = 'sent_to_provider';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';

    /**
     * Nothing this application does will move the message on from here. Provider feedback
     * still can: a delivered message can later be complained about.
     */
    public function isTerminalForSending(): bool
    {
        return $this !== self::Queued;
    }

    /**
     * True only where a receiving mail server is known to have accepted the message. No
     * other state means delivery, and `sent_to_provider` in particular does not.
     */
    public function meansMailboxAccepted(): bool
    {
        return $this === self::Delivered || $this === self::Complained;
    }

    /**
     * Feedback ordering guard. Providers retry their own webhooks and deliver them out of
     * order, so a late `delivered` must not overwrite a `bounced` that came after it, and a
     * duplicate `accepted` must not walk a delivered message backwards.
     *
     * Rank is monotonic within the sending path and lets negative outcomes (bounce,
     * complaint, failure) always win, because they are the states an operator must see.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::SentToProvider => 1,
            self::Accepted => 2,
            self::Delivered => 3,
            self::Failed => 4,
            self::Bounced => 5,
            self::Complained => 6,
        };
    }

    public function supersedes(self $current): bool
    {
        return $this->rank() > $current->rank();
    }
}
