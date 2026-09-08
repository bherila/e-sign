<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use App\Domain\Delivery\Webhooks\TextRedactor;

/**
 * Strips the parts of a transport error or a provider payload that must not be stored.
 *
 * Every rule here exists because a real transport puts the thing there. An SMTP rejection
 * quotes the envelope recipient back at you; a Brevo API error echoes the request, URLs and
 * all; a Symfony exception message can carry the DSN it was built from, which contains the
 * API key. `outbound_mails.last_error` and `outbound_mail_events.payload` are read by
 * operators and copied into tickets, so what lands there is a redacted sentence, not a
 * verbatim provider response.
 *
 * Credential scrubbing is delegated to the webhook outbox's TextRedactor rather than
 * reimplemented. Two redactors solving one problem is how you end up with two different
 * sets of holes — before this delegation, `passphrase: …` survived here while an email
 * address survived there. TextRedactor also draws the opaque-run threshold at 32 characters
 * specifically so a 26-character ULID stays readable, which matters because those are the
 * identifiers an operator correlates on.
 *
 * TODO: TextRedactor is shared infrastructure that happens to live under `Webhooks/`. It
 * belongs in a namespace neither submodule owns; left in place for now so this change does
 * not rename a class the webhook outbox is still being built around.
 *
 * What stays here is what is specific to mail. Addresses go first, because an address is
 * also the tail of a URL-ish string and would otherwise survive inside one. Whole URLs go
 * next as single units, so the token in a signing link disappears with the link rather than
 * being partially matched and leaving the host behind.
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

    public function __construct(private readonly TextRedactor $credentials = new TextRedactor) {}

    public function text(string $error): string
    {
        // Addresses first: `<someone@example.test>` inside an SMTP reply, and the tail of
        // anything that looks like one.
        $error = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[address]', $error) ?? $error;

        // Whole URLs, so a path or query token goes with the link that carried it.
        $error = preg_replace('#\b[a-z][a-z0-9+.\-]*://[^\s"\'<>\)\]]+#i', '[url]', $error) ?? $error;

        // Everything else — JWTs, `Bearer …`, labelled credentials, bare opaque runs,
        // control characters, whitespace, and the length cap.
        $error = $this->credentials->redact($error, maxBytes: self::MAX_LENGTH);

        if ($error === '') {
            return 'The transport failed without a usable message.';
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
