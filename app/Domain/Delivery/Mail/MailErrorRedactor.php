<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

/**
 * Strips the parts of a transport error or a provider payload that must not be stored.
 *
 * Every one of these substitutions exists because a real transport puts the thing there.
 * An SMTP rejection quotes the envelope recipient back at you; a Brevo API error echoes the
 * request, URLs and all; a Symfony exception message can carry the DSN it was built from,
 * which contains the API key. `outbound_mails.last_error` and `outbound_mail_events.payload`
 * are read by operators and copied into tickets, so what lands there is a redacted sentence,
 * not a verbatim provider response.
 *
 * The order matters. Addresses go first, because an address is also the tail of a URL-ish
 * string and would otherwise survive inside one. URLs go next as whole units, so the token
 * in a signing link disappears with the link rather than being matched separately. Only
 * then are bare high-entropy runs removed, which is what catches a key pasted into a
 * message with no surrounding syntax.
 *
 * This is a redactor, not a security boundary. It reduces the blast radius of an error
 * message; it does not license putting a secret in one.
 */
final class MailErrorRedactor
{
    /** Errors are diagnostic, not archival: a truncated sentence beats an essay in a column. */
    public const MAX_LENGTH = 500;

    /**
     * Payload keys dropped wholesale, matched case-insensitively as a substring. Provider
     * bodies are not a fixed schema, so this is a denylist over key *names* rather than a
     * schema mapping: a new Brevo field called `recipient_email` is redacted the day it
     * appears rather than the day someone notices.
     */
    private const DENIED_KEY_FRAGMENTS = [
        'email',
        'address',
        'recipient',
        'token',
        'secret',
        'password',
        'signature',
        'certurl',
        'subscribeurl',
        'unsubscribe',
        'apikey',
        'api_key',
        'authorization',
        'cookie',
    ];

    /** Guards against a hostile provider body inflating a JSON column. */
    private const MAX_PAYLOAD_DEPTH = 6;

    public function text(string $error): string
    {
        $error = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $error) ?? $error;

        // Addresses first: `<someone@example.test>` inside an SMTP reply, and the tail of
        // anything that looks like one.
        $error = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[address]', $error) ?? $error;

        // Whole URLs, so a path or query token goes with the link that carried it.
        $error = preg_replace('#\b[a-z][a-z0-9+.\-]*://[^\s"\'<>\)\]]+#i', '[url]', $error) ?? $error;

        // `token=...` and `api-key: ...` with no URL around them. A separator is required
        // so the words themselves survive in a sentence like "signature verification
        // failed", which an operator needs to be able to read.
        $error = preg_replace(
            '/\b(bearer|token|api[_\-]?key|secret|password|signature)\s*[:=]\s*\S+/i',
            '$1=[redacted]',
            $error
        ) ?? $error;

        // `Authorization: Bearer <value>` style, where the separator sits before the scheme.
        $error = preg_replace('/\bbearer\s+\S+/i', 'bearer [redacted]', $error) ?? $error;

        // A bare high-entropy run. 24 characters is above anything in ordinary prose or in
        // a PHP class name segment, and below the length of every credential format in use.
        $error = preg_replace('/\b[A-Za-z0-9_\-]{24,}={0,2}\b/', '[token]', $error) ?? $error;

        $error = trim(preg_replace('/\s+/u', ' ', $error) ?? $error);

        if ($error === '') {
            return 'The transport failed without a usable message.';
        }

        if (mb_strlen($error) > self::MAX_LENGTH) {
            return mb_substr($error, 0, self::MAX_LENGTH - 1).'…';
        }

        return $error;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function payload(array $payload, int $depth = 0): array
    {
        if ($depth >= self::MAX_PAYLOAD_DEPTH) {
            return ['_' => '[truncated]'];
        }

        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isDeniedKey($key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->payload($value, $depth + 1),
                is_string($value) => $this->text($value),
                is_scalar($value), $value === null => $value,
                // An object in a decoded JSON body means somebody passed something other
                // than a decoded JSON body.
                default => '[unsupported]',
            };
        }

        return $redacted;
    }

    private function isDeniedKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::DENIED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
