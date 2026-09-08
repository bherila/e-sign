<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * Configuration check only: it never sends mail. A non-delivering mailer
 * (`log` or `array`) is fine in development but is a failure in production,
 * where it would silently drop every outbound message.
 */
final class MailProbe implements HealthProbe
{
    private const NON_DELIVERING_MAILERS = ['log', 'array'];

    public function name(): string
    {
        return 'mail';
    }

    public function check(): ProbeResult
    {
        $mailer = (string) config('mail.default');

        if (app()->environment('production') && in_array($mailer, self::NON_DELIVERING_MAILERS, true)) {
            return ProbeResult::fail($this->name(), 'A non-delivering mail transport is configured in production.');
        }

        return ProbeResult::ok($this->name(), 'Mail transport is configured.');
    }
}
