<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use App\Mail\AdminFailureMail;
use App\Mail\CancelledMail;
use App\Mail\CompletedMail;
use App\Mail\DeclinedMail;
use App\Mail\InvitationMail;
use App\Mail\OutboundMailable;
use App\Mail\ReminderMail;

/**
 * The transactional messages this application sends. There are six, they are a closed set,
 * and each one maps to exactly one Mailable.
 *
 * A kind also declares whether its content requires the one action URL, which is what lets
 * MailOutbox refuse an invitation with no link before a queue worker discovers the same
 * problem while rendering.
 */
enum MailKind: string
{
    case Invitation = 'invitation';
    case Reminder = 'reminder';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case AdminFailure = 'admin_failure';

    /**
     * @return class-string<OutboundMailable>
     */
    public function mailableClass(): string
    {
        return match ($this) {
            self::Invitation => InvitationMail::class,
            self::Reminder => ReminderMail::class,
            self::Declined => DeclinedMail::class,
            self::Cancelled => CancelledMail::class,
            self::Completed => CompletedMail::class,
            self::AdminFailure => AdminFailureMail::class,
        };
    }

    public function mailable(MailContext $context): OutboundMailable
    {
        $class = $this->mailableClass();

        return new $class($context);
    }
}
