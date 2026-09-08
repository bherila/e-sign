<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Webhooks\WebhookTransport;
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

    /** Seconds. Confirming a subscription is a one-shot AWS call, not a long job. */
    private const CONFIRM_TIMEOUT = 10;

    public function __construct(
        private readonly SesEventMapper $mapper,
        private readonly MailFeedbackRecorder $recorder,
        private readonly HttpFactory $http,
        private readonly DestinationPolicy $destinations,
    ) {}

    /**
     * Confirm a topic subscription by fetching the URL AWS supplied.
     *
     * The URL comes out of a request body, so it is treated as hostile until proved
     * otherwise, in two stages that catch different things.
     *
     * The host pin is first and is the narrow one: an SNS confirmation goes to
     * `sns.<region>.amazonaws.com` over HTTPS or it goes nowhere. It costs no DNS and it
     * rejects the obvious attempts — `sns.us-east-1.amazonaws.com.attacker.test`, a
     * literal address, the instance metadata endpoint.
     *
     * The shared DestinationPolicy is second and adds what a name pin cannot: it refuses a
     * host that *resolves* to a loopback, private, link-local, or otherwise reserved
     * address, and it returns the addresses it checked so the connection can be pinned to
     * them. Without that, a poisoned or hijacked answer for a legitimate AWS name would
     * still reach an internal service.
     *
     * @param  array<string, mixed>  $envelope
     *
     * @throws RuntimeException When the URL is not an AWS SNS endpoint reachable under the
     *                          destination policy. DestinationRefusedException is one of
     *                          these.
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

        $destination = $this->destinations->for('SNS subscription confirmation')->validate($url);

        // The same transport options the webhook outbox uses, for the same reason: only the
        // first hop was validated, so redirects are never followed, and the connection is
        // pinned to the addresses that were actually checked.
        $this->http
            ->timeout(self::CONFIRM_TIMEOUT)
            ->withOptions(WebhookTransport::transportOptions($destination))
            ->get($destination->url)
            ->throw();
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
