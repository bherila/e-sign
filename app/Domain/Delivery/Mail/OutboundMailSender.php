<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Evidence\Retention\Exceptions\RestoreDrillActive;
use App\Domain\Evidence\Retention\RestoreDrill;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Throwable;

/**
 * Hands one outbox row to a transport and records what came back.
 *
 * Split out of the queue job so that attempt accounting, redaction, and the
 * `sent_to_provider` transition can be exercised directly, without a queue and without
 * relying on how a particular driver treats retries. The job owns *when* this runs; this
 * class owns *what is recorded*.
 *
 * The strongest claim it will make is `sent_to_provider`: a transport accepted the bytes
 * and named the message. It never writes `delivered`. Only provider feedback can, and only
 * through MailFeedbackRecorder.
 */
final class OutboundMailSender
{
    public function __construct(
        private readonly MailFactory $mailer,
        private readonly ProductionMailerGuard $guard,
        private readonly MailErrorRedactor $redactor,
        private readonly RestoreDrill $restoreDrill,
    ) {}

    /**
     * @throws NonDeliveringMailerException Checked again here, not only at enqueue time:
     *                                      configuration can change between the two, and a
     *                                      worker that has been running since before the
     *                                      change is exactly how a `log` mailer would
     *                                      otherwise get to claim a send.
     * @throws RestoreDrillActive When this instance is a restored copy. Checked here and not
     *                            only in MailOutbox for the same reason the mailer guard is:
     *                            a restored database already holds rows that were queued
     *                            before the backup was taken, so guarding only the enqueue
     *                            end would suppress nothing that matters.
     * @throws Throwable Whatever the transport raised, so the queue can retry it.
     */
    public function send(OutboundMail $mail): void
    {
        $this->guard->assertDeliverable();
        $this->restoreDrill->assertNotDrilling('to send outbound mail');

        $mailerName = $this->guard->mailerName();

        // Counted before the attempt, so a worker killed mid-send still leaves evidence
        // that something was tried. An attempt that is not recorded is an attempt that
        // repeats forever.
        $mail->recordAttemptStarted();

        $mailable = $mail->kind
            ->mailable($mail->mailContext())
            ->to($mail->to_email, $mail->to_name);

        try {
            $sent = $this->mailer->mailer($mailerName)->send($mailable);
        } catch (Throwable $exception) {
            $redacted = $this->redactor->text($exception->getMessage());

            // Still `queued`: nothing has been handed over, and the queue decides whether
            // there is another attempt.
            $mail->recordAttemptFailure($mailerName, $redacted);
            $mail->recordEvent(MailEventSource::App, 'attempt_failed', [
                'attempt' => $mail->attempts,
                'mailer' => $mailerName,
                'error' => $redacted,
            ]);

            throw $exception;
        }

        // Null under Mail::fake() and under a transport that rejected the message through an
        // event listener rather than an exception. A row with no Message-ID can never be
        // matched to provider feedback, which is a limitation worth seeing rather than
        // papering over with a locally invented identifier.
        $messageId = MessageId::normalize($sent?->getSymfonySentMessage()->getMessageId());

        $mail->markSentToProvider($mailerName, $messageId);
        $mail->recordEvent(MailEventSource::App, 'sent_to_provider', [
            'attempt' => $mail->attempts,
            'mailer' => $mailerName,
            'has_message_id' => $messageId !== null,
        ]);
    }
}
