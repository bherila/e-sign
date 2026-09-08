<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /webhooks/mail/brevo.
 *
 * Brevo signs nothing. There is no HMAC, no shared secret in a header it computes, and no
 * published source-IP range to allowlist — the entire authentication story for this
 * endpoint is a token embedded in the URL Brevo is configured to call. That is why the
 * check is here, in authorize(), and why an unset token disables the endpoint instead of
 * opening it: an unauthenticated version of this route would let anyone mark any message
 * bounced or delivered.
 *
 * The token is accepted in a header or in the query string. The header is preferable — it
 * stays out of Brevo's own logs and out of ours — but Brevo's webhook configuration is a
 * URL field, so the query string is the mechanism that actually works. Both are compared
 * with hash_equals; a length-leaking comparison on a shared token is a solved problem and
 * not one worth re-opening.
 *
 * Brevo posts a single event object, an array of them, or `{"events": [...]}` depending on
 * the account and the event type, so the body is normalized to one shape before validation
 * rather than every consumer guessing.
 */
class BrevoWebhookRequest extends FormRequest
{
    public const TOKEN_HEADER = 'X-Esign-Mail-Token';

    public function authorize(): bool
    {
        $configured = (string) config('esign.mail.brevo_webhook_token');

        if ($configured === '') {
            // Not configured is not the same as not required. With no token there is
            // nothing to check, so nothing is accepted.
            return false;
        }

        $provided = $this->providedToken();

        if ($provided === '') {
            return false;
        }

        return hash_equals($configured, $provided);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // At least one. A body with nothing that looks like an event is a
            // misconfiguration or a probe, and a 422 says so rather than a cheerful 200.
            'events' => ['array', 'min:1', 'max:200'],
            'events.*' => ['array'],
            'events.*.event' => ['required', 'string', 'max:64'],
            'events.*.message_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The normalized events, ready for BrevoFeedbackProcessor.
     *
     * Deliberately the normalized *input* rather than `validated()`. Validation strips
     * every key it does not name, and the keys it does not name are the ones worth keeping:
     * the bounce reason, the SMTP code, the tag. Those go into the event payload — through
     * MailErrorRedactor, which removes addresses, URLs, and tokens — because without them
     * an operator can see that a message bounced but not why.
     *
     * The shape is still guaranteed: validation has already run and every entry has a
     * string `event`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function events(): array
    {
        /** @var array<int, array<string, mixed>> $events */
        $events = (array) $this->input('events', []);

        return $events;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->json()->all();

        if (! is_array($payload) || $payload === []) {
            $payload = $this->all();
        }

        $events = match (true) {
            array_is_list($payload) => $payload,
            is_array($payload['events'] ?? null) => $payload['events'],
            // A single event object, which is what Brevo sends by default.
            default => [$payload],
        };

        $normalized = [];

        foreach ($events as $event) {
            // Anything with no event name is not an event. Dropped here rather than failing
            // the batch: Brevo redelivers a whole batch on a non-2xx, so one unusable entry
            // would make it retry the usable ones forever.
            if (is_array($event) && is_string($event['event'] ?? null) && trim($event['event']) !== '') {
                $normalized[] = $this->normalizeEvent($event);
            }
        }

        $this->replace(['events' => $normalized]);
    }

    /**
     * Brevo spells the Message-ID three ways across its event types. Normalizing to one key
     * here means the mapper and the recorder never have to know that.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        foreach (['message_id', 'message-id', 'messageId'] as $key) {
            $value = $event[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $event['message_id'] = $value;

                break;
            }
        }

        return $event;
    }

    private function providedToken(): string
    {
        $header = (string) $this->header(self::TOKEN_HEADER, '');

        if (trim($header) !== '') {
            return trim($header);
        }

        return trim((string) $this->query('token', ''));
    }
}
