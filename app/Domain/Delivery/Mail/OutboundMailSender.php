<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Evidence\Retention\Exceptions\RestoreDrillActive;
use App\Domain\Evidence\Retention\RestoreDrill;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mime\Message;
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
        $messageId = MessageId::normalize($this->providerMessageId($sent));

        $mail->markSentToProvider($mailerName, $messageId);
        $mail->recordEvent(MailEventSource::App, 'sent_to_provider', [
            'attempt' => $mail->attempts,
            'mailer' => $mailerName,
            'has_message_id' => $messageId !== null,
        ]);
    }

    /**
     * The identifier the *provider* will use when it reports back on this message.
     *
     * Usually that is `SentMessage::getMessageId()`, which Symfony fills from the transport's
     * own answer: the SMTP `250 Ok <id>` reply, or the id a Brevo API response returns.
     *
     * The SES API transport is the exception, and it was a silent one. Laravel's
     * `Illuminate\Mail\Transport\SesTransport` takes the `MessageId` that `SendRawEmail`
     * returns and adds it as the `X-Message-ID` and `X-SES-Message-ID` headers — it never
     * calls `SentMessage::setMessageId()`. So `getMessageId()` falls back to the RFC 5322
     * `Message-ID` Symfony generated locally, while every SES bounce, complaint, and delivery
     * notification reports `mail.messageId`, which is the SES id. The two never match, so
     * with `MAIL_MAILER=ses` every notification would have been recorded as an orphan and no
     * message would ever have left `sent_to_provider`.
     *
     * Preferring the header fixes it at the one place that writes the column. It is a header
     * read, not an `instanceof` on a transport: nothing here needs to know which mailer is
     * configured, and a mailer that does not set the header is unaffected.
     */
    private function providerMessageId(?SentMessage $sent): ?string
    {
        if ($sent === null) {
            return null;
        }

        $symfony = $sent->getSymfonySentMessage();
        $original = $symfony->getOriginalMessage();

        if ($original instanceof Message) {
            $header = $original->getHeaders()->get('X-SES-Message-ID');

            if ($header !== null && trim($header->getBodyAsString()) !== '') {
                return trim($header->getBodyAsString());
            }
        }

        return $symfony->getMessageId();
    }
}
