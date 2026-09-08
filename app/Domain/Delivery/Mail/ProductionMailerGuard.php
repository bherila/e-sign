<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Mail;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The gate between "this deployment is configured to send mail" and "this deployment writes
 * mail to a file".
 *
 * The allowlist is a constant rather than configuration on purpose. If it were
 * configurable, the one thing it exists to prevent — a deployment where the mail settings
 * say `log` — would be reachable by editing mail settings, which is exactly the mistake
 * being guarded against.
 *
 * Outside production nothing is refused: `log` and `array` are how development and the test
 * suite work, and refusing them there would mean the outbox could only be exercised against
 * a real provider.
 */
final class ProductionMailerGuard
{
    /**
     * Mailers a production deployment may use. `hybrid` is the shared-hosting default
     * (Brevo API, then the host's SMTP relay); `brevo`, `smtp`, and `ses` are the single
     * transports. Every other mailer in config/mail.php is either non-delivering (`log`,
     * `array`), unconfigured in this application (`postmark`, `resend`, `sendmail`), or a
     * composite that includes a non-delivering leg (`failover`, whose second leg is `log`).
     */
    public const DELIVERING_MAILERS = ['hybrid', 'brevo', 'smtp', 'ses'];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    /** The mailer name every outbound message will be sent through. */
    public function mailerName(): string
    {
        return (string) $this->config->get('mail.default');
    }

    public function isDeliverable(): bool
    {
        if (! $this->app->environment('production')) {
            return true;
        }

        return in_array($this->mailerName(), self::DELIVERING_MAILERS, true);
    }

    /**
     * @throws NonDeliveringMailerException
     */
    public function assertDeliverable(): void
    {
        if ($this->isDeliverable()) {
            return;
        }

        throw new NonDeliveringMailerException(sprintf(
            "Refusing to send mail: MAIL_MAILER is '%s', which cannot deliver, and APP_ENV is production. ".
            'A logged or discarded message is not a delivered message. Set MAIL_MAILER to one of: %s.',
            $this->mailerName(),
            implode(', ', self::DELIVERING_MAILERS),
        ));
    }
}
