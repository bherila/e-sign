<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail\Feedback;

/**
 * The knobs on the SES feedback path, read once from `esign.mail.ses` and passed as a value
 * rather than re-read from config in each collaborator.
 *
 * Every default here is the closed one. Subscriptions are not auto-confirmed, SHA-1
 * signatures are not accepted, and the replay window is narrow; an operator widens each
 * deliberately, in one place, where the reason can be written down.
 */
final readonly class SesFeedbackSettings
{
    public function __construct(
        public bool $autoConfirmSubscriptions = false,
        public bool $allowSignatureVersion1 = false,
        public int $replayWindowSeconds = 900,
        public int $certificateCacheTtlSeconds = 3600,
        public int $refusalRecordWindowSeconds = 300,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            autoConfirmSubscriptions: (bool) ($config['auto_confirm_subscriptions'] ?? false),
            allowSignatureVersion1: (bool) ($config['allow_signature_version_1'] ?? false),
            // Floors, not clamps on nonsense: a zero or negative window would mean "refuse
            // everything" for the replay check and "cache forever" for the certificate,
            // which are two different kinds of surprise from one mistyped .env line.
            replayWindowSeconds: max(1, (int) ($config['replay_window_seconds'] ?? 900)),
            certificateCacheTtlSeconds: max(1, (int) ($config['certificate_cache_ttl_seconds'] ?? 3600)),
            refusalRecordWindowSeconds: max(0, (int) ($config['refusal_record_window_seconds'] ?? 300)),
        );
    }
}
