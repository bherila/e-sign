<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Assert;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * The one-time code a test needs, obtained the way a recipient obtains it.
 *
 * The obvious way — read `outbound_mails.context['otp_code']` — stopped working when the
 * code became something the row drops as soon as the message has gone
 * (docs/security/review-2026-09.md finding D-4). That is the point of the fix, so this reads
 * the code out of the message that was actually sent instead, and falls back to the stored
 * context for a row that is still queued.
 *
 * Reading the sent message is also the better test: it asserts the code reached the mailbox,
 * not merely that it reached a column.
 */
trait ReadsMailedOtpCodes
{
    protected function mailedOtpCode(): string
    {
        $mail = OutboundMail::query()
            ->where('kind', MailKind::Otp->value)
            ->orderByDesc('id')
            ->firstOrFail();

        $stored = $mail->context['otp_code'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->codeInLastSentMessage();
    }

    /** The code as it appears in the message body, which is where the recipient reads it. */
    private function codeInLastSentMessage(): string
    {
        $transport = Mail::mailer((string) config('mail.default'))->getSymfonyTransport();

        Assert::assertInstanceOf(
            ArrayTransport::class,
            $transport,
            'Reading a mailed code needs the array transport; phpunit.xml pins MAIL_MAILER=array.',
        );

        /** @var SentMessage|null $sent */
        $sent = $transport->messages()->last();

        Assert::assertNotNull($sent, 'No message was sent, so there is no code to read.');

        $message = $sent->getOriginalMessage();

        Assert::assertInstanceOf(Email::class, $message);

        // The HTML body only, and only a run of digits that is the whole text of an element.
        // Scanning the serialized message instead would also scan the MIME boundary and the
        // Message-ID, both of which are random hex and can contain a bounded run of digits
        // of exactly this length — which is a test that fails a few runs in a hundred.
        $length = (int) config('esign.signing.otp.length', 6);

        Assert::assertSame(
            1,
            preg_match('/>\s*(\d{'.$length.'})\s*</', (string) $message->getHtmlBody(), $matches),
            'The sent message does not present a code of the configured length.',
        );

        return $matches[1];
    }
}
