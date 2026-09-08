<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Retention;

use App\Domain\Evidence\Retention\Exceptions\RestoreDrillActive;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Whether this process is a restored copy, and what that forbids.
 *
 * A restore drill is the only way to find out whether a backup is a backup, and the only
 * dangerous thing about it is that the copy is indistinguishable from the original from the
 * inside. It holds the same recipient addresses, the same webhook URLs, the same queued
 * mail and the same pending deliveries. Start a worker against it and the drill sends real
 * invitations to real people for agreements they signed last year.
 *
 * So the environment says so — `ESIGN_RESTORE_DRILL=1` — and this class is the one place
 * that reads it. Two obligations follow, in opposite directions:
 *
 *  - **Outbound paths must refuse.** {@see assertNotDrilling()} is called by
 *    `MailOutbox`, `OutboundMailSender`, `WebhookDispatcher`, and the webhook delivery job.
 *    Refusing at both the enqueue and the send end is deliberate, exactly as
 *    `ProductionMailerGuard` is: a restored database already contains rows that were queued
 *    before the backup was taken, so guarding only the enqueue end would suppress nothing
 *    that matters.
 *  - **`esign:restore:verify` must refuse without it.** {@see assertDrilling()} is the other
 *    half, and it also refuses when `APP_ENV=production`. The two together mean a drill
 *    command cannot be aimed at a live instance by a mistyped host, and a live instance
 *    cannot be turned into a silent one by a stray environment variable.
 *
 * There is no way to ask for the flag other than through this class, and it is read from
 * config rather than from `env()` at the call site so it keeps working under `config:cache`.
 */
final readonly class RestoreDrill
{
    public function __construct(
        private Repository $config,
        private Application $app,
    ) {}

    public function isActive(): bool
    {
        return $this->config->get('esign.restore_drill') === true;
    }

    /**
     * Refuse an outbound action while this instance is a restored copy.
     *
     * @param  string  $what  A short gerund phrase naming the action, e.g. "to queue mail".
     *
     * @throws RestoreDrillActive
     */
    public function assertNotDrilling(string $what): void
    {
        if ($this->isActive()) {
            throw RestoreDrillActive::refusing($what);
        }
    }

    /**
     * Refuse a drill-only command unless this really is a throwaway restore environment.
     *
     * Both conditions are required. `ESIGN_RESTORE_DRILL=1` alone is a variable somebody
     * could set anywhere; `APP_ENV != production` alone is true of every staging instance
     * that is not a restore at all. Asking for both means the command runs only where
     * somebody deliberately built a copy to run it in.
     *
     * @throws RetentionRefused
     */
    public function assertDrilling(): void
    {
        if ($this->app->environment('production')) {
            throw new RetentionRefused(
                'Refusing to run a restore drill with APP_ENV=production. A drill verifies a copy; '
                .'running it against the instance that is serving traffic proves nothing and risks '
                .'everything. Restore into a throwaway environment and set APP_ENV there.',
            );
        }

        if (! $this->isActive()) {
            throw new RetentionRefused(
                'Refusing to run a restore drill without ESIGN_RESTORE_DRILL=1. That variable is '
                .'also what stops the restored copy sending mail and webhooks to the addresses and '
                .'endpoints it inherited, so a drill without it is a drill that contacts real people.',
            );
        }
    }
}
