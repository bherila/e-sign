<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Jobs;

use App\Domain\Delivery\Mail\MailErrorRedactor;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\OutboundMailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one outbox row.
 *
 * Carries the message's public ULID rather than the model. A serialized model would be
 * re-fetched anyway, and a job holding a snapshot of a row whose state has since moved is
 * how a message gets sent twice.
 *
 * `ShouldBeUnique` keyed on that ULID is the second guard against a duplicate send. It
 * matters most in the case that is otherwise hard to see: an operator resending, a retried
 * enqueue after a timeout, or a worker restarting mid-dispatch can all put two jobs for one
 * row on the queue, and the recipient would get two copies of the same invitation with the
 * same link.
 *
 * Retries are bounded by config (`esign.mail.tries`, `esign.mail.backoff`). While attempts
 * remain the row stays `queued`, because that is what is true — nothing has been handed
 * over. Only when the queue gives up does `failed()` write `failed`.
 */
class SendOutboundMail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The row and this job are written in one transaction, so the job must not become
     * visible to a worker before that transaction commits. That is set at dispatch time by
     * MailOutbox (`->afterCommit()`) rather than by an `$afterCommit` property here: the
     * `Queueable` trait already declares that property with a different default, and PHP
     * rejects a redeclaration that does not match exactly.
     */
    public function __construct(public readonly string $mailPublicId) {}

    /** One in-flight job per message. */
    public function uniqueId(): string
    {
        return $this->mailPublicId;
    }

    /**
     * The lock is released when the job finishes, so this is only a ceiling for the case
     * where a worker dies holding it. Long enough to cover the whole retry schedule,
     * short enough that a resend is not blocked for a day.
     */
    public function uniqueFor(): int
    {
        return 6 * 3600;
    }

    public function tries(): int
    {
        return max(1, (int) config('esign.mail.tries', 5));
    }

    /**
     * Seconds to wait before each retry. The list is consumed positionally by the queue and
     * its last value repeats, so a short list means a fixed final interval rather than an
     * immediate retry.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        /** @var array<int, int|string> $configured */
        $configured = (array) config('esign.mail.backoff', []);

        $backoff = array_values(array_filter(
            array_map(static fn (int|string $seconds): int => (int) $seconds, $configured),
            static fn (int $seconds): bool => $seconds > 0,
        ));

        return $backoff === [] ? [60] : $backoff;
    }

    public function handle(OutboundMailSender $sender): void
    {
        $mail = $this->mail();

        if ($mail === null) {
            // The row is gone. Nothing to send and nothing to record; a job that outlives
            // its row is not an error worth failing over.
            return;
        }

        if ($mail->state !== MailState::Queued) {
            // Already handed to a transport, already given up on, or already reported on by
            // a provider. Re-sending would mean a second copy in the recipient's mailbox.
            return;
        }

        $sender->send($mail);
    }

    /**
     * The queue has given up: no attempts remain, or the job was failed explicitly.
     *
     * This is the only place `failed` is written. It is deliberately not written by the
     * sender, because the sender cannot know whether the queue intends to try again.
     */
    public function failed(?Throwable $exception): void
    {
        $mail = $this->mail();

        if ($mail === null || $mail->state !== MailState::Queued) {
            return;
        }

        $redactor = app(MailErrorRedactor::class);
        $reason = $exception === null
            ? 'The send job failed without an exception.'
            : $redactor->text($exception->getMessage());

        $mail->markFailed($reason);
        $mail->recordEvent(MailEventSource::App, 'failed', [
            'attempts' => $mail->attempts,
            'error' => $reason,
        ]);
    }

    private function mail(): ?OutboundMail
    {
        return OutboundMail::query()
            ->where('public_id', $this->mailPublicId)
            ->first();
    }
}
