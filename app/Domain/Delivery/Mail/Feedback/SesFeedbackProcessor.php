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
 * Everything here runs *behind* SnsMessageVerifier, and that ordering is the design: an
 * envelope reaches this class only once its SNS signature has verified against an AWS
 * certificate and its topic has been matched against the allowlist. Nothing here re-checks
 * either, and nothing here should be called from anywhere that has not.
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
        private readonly SesFeedbackSettings $settings = new SesFeedbackSettings,
    ) {}

    /**
     * Whether this deployment confirms an SNS subscription by itself.
     *
     * Off by default. Confirming a subscription is the act that starts this deployment
     * receiving a topic's traffic, and it is performed by fetching a URL that arrived in a
     * request body — so the default is that a person does it once, in the SNS console, where
     * they can see what they are subscribing to. An automated deployment that recreates its
     * topic subscription turns it on deliberately.
     *
     * The signature is still checked and the topic is still allowlisted before this is even
     * consulted: the switch narrows an already-verified action, it does not stand in for
     * verification.
     */
    public function autoConfirmEnabled(): bool
    {
        return $this->settings->autoConfirmSubscriptions;
    }

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

        [$primaryMessageId, $alternateMessageIds] = $this->messageIds($message);

        $payload = $message;
        $classification = $this->mapper->bounceClassification($message);

        if ($classification !== null) {
            // Ours first, so a provider body carrying a key of the same name cannot rewrite
            // the classification an operator reads.
            $payload = $classification + $payload;
        }

        $this->recorder->record(
            source: MailEventSource::Ses,
            event: $type,
            messageId: $primaryMessageId,
            state: $this->mapper->map($type),
            payload: $payload,
            occurredAt: $this->occurredAt($message),
            alternateMessageIds: $alternateMessageIds,
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
     * Every identifier this notification offers for the message, most authoritative first.
     *
     * **SES's message id is not the SMTP `Message-ID` header, and the outbox can hold
     * either.** `mail.messageId` in an SES notification is SES's own identifier — the value
     * SES returns from `SendRawEmail` and echoes in the `X-SES-Message-ID` header, shaped
     * like `0100019...`. The RFC 5322 `Message-ID` is generated by Symfony before the message
     * is handed over and looks like `abc@host`. They are different strings for one message,
     * and which one `outbound_mails.message_id` holds depends on the transport:
     *
     *  - `MAIL_MAILER=smtp` against SES's SMTP endpoint: Symfony's SMTP transport parses the
     *    id out of the `250 Ok <id>` reply and calls `SentMessage::setMessageId()`, so the
     *    stored value is already SES's. `mail.messageId` matches directly.
     *  - `MAIL_MAILER=ses` (the SES API): Laravel's `SesTransport` adds the SES id as the
     *    `X-Message-ID`/`X-SES-Message-ID` headers and never calls `setMessageId()`, so
     *    `SentMessage::getMessageId()` returns the RFC 5322 header instead. Left alone, every
     *    SES notification would have been an orphan. `OutboundMailSender` now prefers the
     *    `X-SES-Message-ID` header, which fixes it going forward.
     *
     * The header fallback here covers what that fix cannot: rows sent before it, and any
     * future transport that stores the RFC 5322 id. SES carries the original `Message-ID`
     * only when the notification is configured to include original headers, so it is a
     * fallback rather than the primary — it is often simply absent.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string|null, 1: list<string|null>}
     */
    private function messageIds(array $message): array
    {
        $mail = $message['mail'] ?? null;

        if (! is_array($mail)) {
            return [null, []];
        }

        $primary = $mail['messageId'] ?? null;

        return [
            is_string($primary) ? $primary : null,
            array_values(array_filter([
                $this->headerMessageId($mail),
                is_string($mail['commonHeaders']['messageId'] ?? null)
                    ? $mail['commonHeaders']['messageId']
                    : null,
            ])),
        ];
    }

    /**
     * The RFC 5322 `Message-ID` out of SES's `mail.headers` list, when it is included.
     *
     * @param  array<string, mixed>  $mail
     */
    private function headerMessageId(array $mail): ?string
    {
        $headers = $mail['headers'] ?? null;

        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = $header['name'] ?? null;
            $value = $header['value'] ?? null;

            if (is_string($name) && strcasecmp(trim($name), 'Message-ID') === 0 && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * When the event happened, which is not when the message was sent.
     *
     * `mail.timestamp` is the send time and is the same on every notification about one
     * message. The event's own time lives in the type-specific object — `bounce.timestamp`,
     * `complaint.timestamp`, and so on — and it is the one that matters here, because this
     * value becomes `outbound_mails.state_changed_at`. Using the send time would stamp a
     * bounce that arrived an hour later as if it had happened at send, which breaks both the
     * operator timeline and the 24-hour windows the backlog probe and
     * `esign:mail:backlog` read.
     *
     * `mail.timestamp` is still the fallback: a timestamp near the truth beats none.
     *
     * @param  array<string, mixed>  $message
     */
    private function occurredAt(array $message): ?Carbon
    {
        foreach (['bounce', 'complaint', 'delivery', 'deliveryDelay', 'reject', 'send', 'mail'] as $key) {
            $object = $message[$key] ?? null;

            if (! is_array($object)) {
                continue;
            }

            $parsed = $this->parseTimestamp($object['timestamp'] ?? null);

            if ($parsed instanceof Carbon) {
                return $parsed;
            }
        }

        return null;
    }

    private function parseTimestamp(mixed $timestamp): ?Carbon
    {
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
