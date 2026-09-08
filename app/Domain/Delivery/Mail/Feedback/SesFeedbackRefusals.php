<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailErrorRedactor;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\Models\OutboundMailEvent;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Makes a refused SES message visible without making the refusal path a write amplifier.
 *
 * A silent refusal is the failure mode this whole endpoint is built to avoid. An operator
 * who has just configured SES and sees nothing at all cannot tell "the topic ARN is wrong"
 * from "SNS has not sent anything yet" from "the endpoint is unreachable", and the honest
 * answer to each is different. So every refusal is recorded as an `outbound_mail_events`
 * row with `outbound_mail_id` null — the same orphan shape feedback about an unknown
 * Message-ID takes — carrying the reason token and nothing else identifying.
 *
 * The counterweight: `POST /webhooks/mail/ses` is an unauthenticated public surface. One row
 * per hostile request is a storage-growth primitive that anyone on the internet can pull,
 * bounded only by the route's rate limit. So refusals **collapse**: the first refusal of a
 * given reason in the window is recorded, and the rest of that window is logged and not
 * written. The signal an operator needs is "this is happening and here is why", which
 * survives collapsing; "how many times" does not, and the log has it.
 *
 * Nothing attacker-supplied is stored except the topic ARN, which is stored because it is
 * the single most useful fact when the reason is `topic_not_allowlisted` — the mistake it
 * diagnoses is almost always a typo in `ESIGN_MAIL_SES_TOPIC_ARNS` — and it goes through the
 * same redaction and length cap as every other payload. No signature, no certificate URL, no
 * message body, and nothing at all in the log line beyond the reason.
 */
final class SesFeedbackRefusals
{
    private const CACHE_PREFIX = 'esign:sns:refusal:';

    /** ARNs are ~90 characters; anything longer is not one. */
    private const MAX_ARN_LENGTH = 2048;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly MailErrorRedactor $redactor,
        private readonly SesFeedbackSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope  The SNS envelope, untrusted; only its Type and
     *                                          TopicArn are read, and only the ARN is stored.
     */
    public function record(string $reason, array $envelope = []): void
    {
        // Reason only. Not the envelope, not the signature, not the body: this line is
        // written on an unauthenticated request and log files are read by more people, and
        // shipped to more places, than the database is.
        Log::warning('SES feedback refused.', ['reason' => $reason]);

        if (! $this->shouldRecord($reason)) {
            return;
        }

        OutboundMailEvent::create([
            'outbound_mail_id' => null,
            'source' => MailEventSource::Ses,
            'event' => 'refused',
            'message_id' => null,
            'payload' => [
                'orphan' => true,
                'reason' => $reason,
                'sns_type' => $this->redacted($envelope['Type'] ?? null, 64),
                'claimed_topic_arn' => $this->redacted($envelope['TopicArn'] ?? null, self::MAX_ARN_LENGTH),
                'collapsed_window_seconds' => $this->settings->refusalRecordWindowSeconds,
            ],
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * One row per reason per window. `add()` is the atomic "set if absent" the cache
     * contract provides, so two workers refusing the same thing at the same moment write one
     * row between them rather than two.
     */
    private function shouldRecord(string $reason): bool
    {
        if ($this->settings->refusalRecordWindowSeconds <= 0) {
            return true;
        }

        return $this->cache->add(
            self::CACHE_PREFIX.sha1($reason),
            true,
            $this->settings->refusalRecordWindowSeconds,
        );
    }

    /**
     * Attacker-supplied text goes through the same redactor every other stored provider
     * value does, so a control character, a credential-shaped run, or an essay in the
     * `TopicArn` field is handled by one set of rules rather than a second set invented here.
     */
    private function redacted(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = $this->redactor->text(substr($value, 0, $maxLength));

        return $value === '' ? null : $value;
    }
}
