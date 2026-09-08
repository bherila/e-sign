<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

/**
 * A webhook signing secret.
 *
 * 32 bytes from the CSPRNG, hex-encoded, behind a recognisable prefix so a
 * secret that leaks into a paste or a log is identifiable as one at a glance.
 * The plaintext is shown exactly once, when it is created or rotated: it is
 * stored encrypted and there is no command that prints it back.
 */
final class WebhookSecret
{
    public const PREFIX = 'whsec_';

    public static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(32));
    }
}
