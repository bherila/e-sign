<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Support\Str;

/**
 * The one-time code a guest enters to start a signing session (issue #25).
 *
 * The only template in this application that carries a credential in its text rather than in
 * a link, and the only one with no link at all. That is the point: the invitation already
 * went to this address, and a code plus a one-click URL in the same message would let a
 * single forwarded mail satisfy both halves of a check whose entire purpose is that they
 * arrive separately.
 *
 * The subject deliberately does not contain the code. Subjects appear in notification
 * banners on locked screens, in mail-server logs, and in the summary lines of anybody cc'd
 * on a thread the message is later quoted into.
 *
 * The code is short-lived and single-purpose; `$context->expiresAt` says when it stops
 * working, and the body says what to do about it, so a person who reads the mail late is
 * told to ask for another rather than left guessing.
 */
class OtpMail extends OutboundMailable
{
    protected function requiredFields(): array
    {
        return ['recipientName', 'agreementTitle', 'otpCode'];
    }

    public function subjectLine(): string
    {
        return sprintf('Your code for "%s"', Str::limit($this->context->agreementTitle, 120));
    }

    protected function markdownView(): string
    {
        return 'mail.otp';
    }
}
