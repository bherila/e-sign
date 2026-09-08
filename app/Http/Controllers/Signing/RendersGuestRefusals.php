<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Signing\Sessions\Exceptions\GuestAccessDenied;
use App\Domain\Signing\Sessions\Exceptions\GuestRateLimited;
use Illuminate\Http\Response;

/**
 * How the guest surface says no.
 *
 * One place, because the rule it encodes is easy to break one controller at a time: the
 * *reason* is for the audit trail and the tests, and the *page* is deliberately vaguer than
 * the reason.
 *
 * Telling a caller apart "no such invitation" from "that invitation expired" turns the
 * landing page into an oracle: a prober learns which envelope identifiers are real, which
 * tokens were once valid, and which recipients have already signed. None of that helps the
 * person who legitimately clicked an old link, and all of it helps somebody enumerating.
 * What does help the legitimate person is the one instruction that is always right — ask
 * the sender to send it again — so that is what the page says, whichever refusal produced
 * it.
 *
 * Rate limiting is the exception that proves the rule. It is not a statement about the
 * credential, the same request may well succeed later, and the answer therefore carries
 * `Retry-After` and a different status. It still does not say *which* ceiling was hit:
 * telling a prober whether it tripped the per-address or the per-client limit tells it how
 * to spread the next attempt.
 */
trait RendersGuestRefusals
{
    /** 403 with the same page for every access refusal. */
    protected function refuse(GuestAccessDenied $denied, ?string $agreementTitle = null): Response
    {
        return response()->view('signing.unavailable', [
            'reason' => $denied->reason,
            'title' => $agreementTitle,
        ], 403);
    }

    /**
     * 429 with `Retry-After`.
     *
     * The header is in seconds and comes from the limiter rather than from a constant, so a
     * client that honours it waits exactly as long as it has to.
     */
    protected function tooManyAttempts(GuestRateLimited $limited, ?string $agreementTitle = null): Response
    {
        return response()
            ->view('signing.rate-limited', [
                'retry_after' => $limited->retryAfterSeconds,
                'title' => $agreementTitle,
            ], 429)
            ->header('Retry-After', (string) $limited->retryAfterSeconds);
    }
}
