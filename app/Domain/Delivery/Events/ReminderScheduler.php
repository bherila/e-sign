<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailOutbox;
use App\Domain\Delivery\Mail\MailRecipient;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Nudges the people an agreement is waiting on.
 *
 * Three conditions, all of them about what is true rather than about how often the command
 * runs, so running it twice in a minute sends nothing twice:
 *
 * 1. The recipient is `active` — eligible now. A `pending` later signer is not waiting on
 *    anything they can act on, and reminding them would ask for something the ordering guard
 *    would refuse.
 * 2. They were invited more than `esign.signing.reminder_after_hours` ago. Measured from
 *    `invited_at`, the moment we actually wrote to them, not from when the row appeared.
 * 3. They have not been reminded within `esign.signing.reminder_interval_hours`.
 *
 * The envelope itself must still be `sent` or `in_progress`, and must not be past its
 * expiry — a reminder for something that expires in the time it takes to read the message
 * would be worse than silence. `esign:signing:expire` closes those out separately, and the
 * two commands are independent on purpose: whichever runs first, the other's precondition is
 * still evaluated against the row as it is.
 *
 * A reminder carries the same single link as the invitation, minted fresh, because the
 * credential behind an invitation may have rotated or expired since
 * ({@see SigningUrlMinter}).
 */
final readonly class ReminderScheduler
{
    /** Hours to wait after the invitation before the first reminder. */
    public const DEFAULT_AFTER_HOURS = 72;

    /** Hours between reminders to the same person. */
    public const DEFAULT_INTERVAL_HOURS = 24;

    public function __construct(
        private MailOutbox $outbox,
        private SigningUrlMinter $signingUrls,
        private Repository $config,
    ) {}

    /**
     * @return list<EnvelopeRecipient> The recipients that were reminded.
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $invitedBefore = $now->subHours($this->afterHours());
        $remindedBefore = $now->subHours($this->intervalHours());
        $reminded = [];

        foreach ($this->due($invitedBefore, $remindedBefore, $now) as $recipient) {
            $envelope = $recipient->envelope;

            if ($envelope === null) {
                continue;
            }

            $signingUrl = $this->signingUrls->signingUrlFor($recipient);

            $sent = DB::transaction(function () use ($envelope, $recipient, $signingUrl, $now, $remindedBefore): bool {
                // Re-checked as a conditional update so two schedulers — a cron overlapping
                // itself, or a manual run beside the daily one — cannot both send.
                $claimed = $recipient->newQuery()
                    ->whereKey($recipient->getKey())
                    ->where(function ($query) use ($remindedBefore): void {
                        $query->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', $remindedBefore);
                    })
                    ->update(['last_reminded_at' => $now]);

                if ($claimed !== 1) {
                    return false;
                }

                $this->outbox->enqueue(
                    kind: MailKind::Reminder,
                    recipient: new MailRecipient($recipient->email, $recipient->name),
                    context: new MailContext(
                        recipientName: $recipient->name,
                        senderName: EnvelopeAudience::senderName($envelope),
                        agreementTitle: $envelope->title,
                        actionUrl: $signingUrl,
                        expiresAt: $envelope->expires_at,
                    ),
                    workspace: $envelope->workspace,
                    related: $recipient,
                );

                return true;
            });

            if ($sent) {
                $recipient->last_reminded_at = $now;
                $recipient->syncOriginalAttribute('last_reminded_at');
                $reminded[] = $recipient;
            }
        }

        return $reminded;
    }

    /**
     * @return list<EnvelopeRecipient>
     */
    private function due(
        CarbonImmutable $invitedBefore,
        CarbonImmutable $remindedBefore,
        CarbonImmutable $now,
    ): array {
        return array_values(
            EnvelopeRecipient::query()
                ->with(['envelope.workspace', 'envelope.creator'])
                ->where('state', RecipientState::Active->value)
                ->whereNotNull('invited_at')
                ->where('invited_at', '<=', $invitedBefore)
                ->where(function ($query) use ($remindedBefore): void {
                    $query->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', $remindedBefore);
                })
                ->whereHas('envelope', function ($query) use ($now): void {
                    $query
                        ->whereIn('state', [EnvelopeState::Sent->value, EnvelopeState::InProgress->value])
                        ->where(function ($expiry) use ($now): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                        });
                })
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    private function afterHours(): int
    {
        return max(1, (int) $this->config->get('esign.signing.reminder_after_hours', self::DEFAULT_AFTER_HOURS));
    }

    private function intervalHours(): int
    {
        return max(1, (int) $this->config->get('esign.signing.reminder_interval_hours', self::DEFAULT_INTERVAL_HOURS));
    }
}
