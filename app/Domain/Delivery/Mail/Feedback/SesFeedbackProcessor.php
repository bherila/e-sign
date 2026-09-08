<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailEventSource;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Handles an SNS envelope that has already been proven to come from AWS.
 *
 * Everything here runs *behind* SnsMessageVerifier. That ordering is the design: the
 * shipped verifier refuses every message (see RejectingSnsMessageVerifier), so this class
 * is currently unreachable over HTTP, and it exists in a reviewed and tested state so that
 * installing a real verifier is the only change needed to turn SES feedback on.
 *
 * SNS delivers the SES notification as a JSON string inside the envelope's `Message`
 * field — JSON within JSON — and the interesting identifiers are two levels down in
 * `mail.messageId`. The nesting is why this is a class rather than three lines in a
 * controller.
 */
final class SesFeedbackProcessor
{
    /**
     * AWS-controlled hosts that may be contacted to confirm a subscription. Confirming a
     * subscription is the one outbound request this endpoint makes, and an attacker who
     * could choose its destination would have a server-side request forgery primitive
     * reachable from an unauthenticated webhook.
     */
    private const SUBSCRIBE_HOST_PATTERN = '/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/';

    public function __construct(
        private readonly SesEventMapper $mapper,
        private readonly MailFeedbackRecorder $recorder,
        private readonly HttpFactory $http,
    ) {}

    /**
     * Confirm a topic subscription by fetching the URL AWS supplied.
     *
     * @param  array<string, mixed>  $envelope
     *
     * @throws RuntimeException When the URL is not an AWS SNS endpoint over HTTPS.
     */
    public function confirmSubscription(array $envelope): void
    {
        $url = is_string($envelope['SubscribeURL'] ?? null) ? $envelope['SubscribeURL'] : '';
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            throw new RuntimeException('An SNS subscription confirmation URL must be an HTTPS URL.');
        }

        if (preg_match(self::SUBSCRIBE_HOST_PATTERN, strtolower($parts['host'])) !== 1) {
            throw new RuntimeException('An SNS subscription confirmation URL must point at an AWS SNS endpoint.');
        }

        // No redirects: the whole point of pinning the host is lost if the first response
        // can send the request somewhere else.
        $this->http->withoutRedirecting()->timeout(10)->get($url)->throw();
    }

    /**
     * Record one SES notification.
     *
     * @param  array<string, mixed>  $envelope
     * @return bool Whether anything was recorded. False means the envelope's `Message` was
     *              not usable SES JSON, which is not an error worth a non-2xx: SNS would
     *              redeliver it forever.
     */
    public function processNotification(array $envelope): bool
    {
        $message = $this->decodeMessage($envelope);

        if ($message === null) {
            return false;
        }

        $type = $this->notificationType($message);

        if ($type === null) {
            return false;
        }

        $this->recorder->record(
            source: MailEventSource::Ses,
            event: $type,
            messageId: $this->messageId($message),
            state: $this->mapper->map($type),
            payload: $message,
            occurredAt: $this->occurredAt($message),
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>|null
     */
    private function decodeMessage(array $envelope): ?array
    {
        $raw = $envelope['Message'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * SES calls it `notificationType` when publishing bounce/complaint/delivery
     * notifications and `eventType` when publishing configuration-set event destinations.
     * Both spellings reach the same topic, so both are read.
     *
     * @param  array<string, mixed>  $message
     */
    private function notificationType(array $message): ?string
    {
        foreach (['notificationType', 'eventType'] as $key) {
            $value = $message[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageId(array $message): ?string
    {
        $mail = $message['mail'] ?? null;

        if (! is_array($mail)) {
            return null;
        }

        $messageId = $mail['messageId'] ?? null;

        return is_string($messageId) ? $messageId : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function occurredAt(array $message): ?Carbon
    {
        $mail = $message['mail'] ?? null;
        $timestamp = is_array($mail) ? ($mail['timestamp'] ?? null) : null;

        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
