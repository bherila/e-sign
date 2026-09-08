<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything a Mailable is allowed to know, as one plain data object.
 *
 * The outbox is built before envelopes exist, so it deliberately does not depend on them.
 * Whatever produces a message — an envelope transition today, an import or a scheduled
 * reminder tomorrow — flattens what it wants said into this object, and the Mailables
 * render only from here. That is also why it is safe to persist: it is view data, it goes
 * into `outbound_mails.context` verbatim, and there is nowhere in it for a secret to hide.
 *
 * There is exactly one URL field on purpose. A transactional message carries at most one
 * opaque credential, so a forwarded or scanner-fetched mail exposes one thing rather than a
 * set, and `$actionUrl` is the single slot for it. It must arrive already authorized and
 * already absolute: this object mints nothing and signs nothing.
 *
 * `$senderName` is the party the message is *about* — the sender or workspace for an
 * invitation — while `$actorName` is whoever performed the action a notice reports: the
 * recipient who declined, the sender who cancelled. They are separate fields because a
 * decline notice goes to the sender and has to name the other party without pretending the
 * sender is that party.
 *
 * `$reason` and `$failureSummary` are operator- or sender-authored prose that will be shown
 * to a human. They are escaped by Blade like any other view data and are never a place to
 * put a stack trace, an address, or a token.
 *
 * `$otpCode` is the one exception to "there is nowhere in it for a secret to hide", and it
 * is a deliberate, bounded one. A one-time code has to reach a queue worker to be rendered,
 * and the worker renders from the persisted context — that is what makes delivery survive a
 * crash (docs/delivery/mail.md). So a live code is readable in `outbound_mails.context` by
 * anyone with database access, for the ten minutes it is worth anything. The check it backs
 * is aimed at somebody holding a forwarded invitation, not at the host administrator, and
 * docs/signing/guest-access.md states that limit rather than implying otherwise.
 */
final class MailContext
{
    /** Query parameters permitted on `$actionUrl`: one token, and nothing else. */
    private const MAX_URL_QUERY_PARAMETERS = 1;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $senderName = '',
        public readonly string $agreementTitle = '',
        public readonly ?string $actionUrl = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $actorName = null,
        public readonly ?string $reason = null,
        public readonly ?string $failureSummary = null,
        public readonly ?string $reference = null,
        public readonly ?string $otpCode = null,
    ) {
        if (trim($recipientName) === '') {
            throw new InvalidArgumentException('A mail context needs a recipient name to address the message to.');
        }

        if ($actionUrl !== null) {
            self::assertUsableActionUrl($actionUrl);
        }
    }

    /**
     * Rehydrate from `outbound_mails.context`. Unknown keys are ignored rather than
     * rejected: a row written by an older release must still render after a deploy that
     * dropped a field, because the alternative is a queue of messages that can never be
     * sent and never be explained.
     *
     * @param  array<string, mixed>  $context
     */
    public static function fromArray(array $context): self
    {
        return new self(
            recipientName: self::string($context, 'recipient_name') ?? '',
            senderName: self::string($context, 'sender_name') ?? '',
            agreementTitle: self::string($context, 'agreement_title') ?? '',
            actionUrl: self::string($context, 'action_url'),
            expiresAt: self::timestamp($context, 'expires_at'),
            actorName: self::string($context, 'actor_name'),
            reason: self::string($context, 'reason'),
            failureSummary: self::string($context, 'failure_summary'),
            reference: self::string($context, 'reference'),
            otpCode: self::string($context, 'otp_code'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'sender_name' => $this->senderName,
            'agreement_title' => $this->agreementTitle,
            'action_url' => $this->actionUrl,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'actor_name' => $this->actorName,
            'reason' => $this->reason,
            'failure_summary' => $this->failureSummary,
            'reference' => $this->reference,
            'otp_code' => $this->otpCode,
        ];
    }

    /**
     * Field names, in this object's own vocabulary, that carry no usable value. Mailables
     * use it to state their requirements once and get a specific error rather than a blank
     * paragraph in a sent message.
     *
     * @param  string[]  $fields
     * @return string[]
     */
    public function missing(array $fields): array
    {
        $missing = [];

        foreach ($fields as $field) {
            $value = match ($field) {
                'recipientName' => $this->recipientName,
                'senderName' => $this->senderName,
                'agreementTitle' => $this->agreementTitle,
                'actionUrl' => $this->actionUrl,
                'expiresAt' => $this->expiresAt,
                'actorName' => $this->actorName,
                'reason' => $this->reason,
                'failureSummary' => $this->failureSummary,
                'reference' => $this->reference,
                'otpCode' => $this->otpCode,
                default => throw new InvalidArgumentException("Unknown mail context field '{$field}'."),
            };

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * A link in a transactional mail must be absolute (a mail client has no base URL to
     * resolve against), must not carry credentials in the authority, and must not carry
     * more than the one opaque token the product allows itself.
     */
    private static function assertUsableActionUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('A mail action URL must be absolute; a mail client has no base URL to resolve against.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('A mail action URL must be http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('A mail action URL must not carry credentials in its authority.');
        }

        if (isset($parts['fragment'])) {
            // A fragment is never sent to the server, so a token in one is a token that
            // cannot be checked, revoked, or logged as used.
            throw new InvalidArgumentException('A mail action URL must not carry a fragment.');
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $parameters = explode('&', $parts['query']);

            if (count($parameters) > self::MAX_URL_QUERY_PARAMETERS) {
                throw new InvalidArgumentException('A mail action URL carries at most one query parameter, so a forwarded message exposes one opaque token rather than a set.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function string(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function timestamp(array $context, string $key): ?CarbonImmutable
    {
        $value = self::string($context, $key);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            // An unparseable expiry renders as "no stated expiry" rather than stopping the
            // message: the link's real lifetime is enforced by whatever issued it.
            return null;
        }
    }
}
