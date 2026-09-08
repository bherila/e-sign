<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Console;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Delivery\Health\Probes\DatabaseProbe;
use App\Domain\Delivery\Health\Probes\Doctor\EnvironmentProbe;
use App\Domain\Delivery\Health\Probes\Doctor\PhpRuntimeProbe;
use App\Domain\Delivery\Health\Probes\Doctor\QueueConnectionProbe;
use App\Domain\Delivery\Health\Probes\Doctor\ResourceLimitsProbe;
use App\Domain\Delivery\Health\Probes\Doctor\WebPhpVersionProbe;
use App\Domain\Delivery\Health\Probes\Doctor\WritablePathsProbe;
use App\Domain\Delivery\Health\Probes\MailProbe;
use App\Domain\Delivery\Health\Probes\SchedulerHeartbeatProbe;
use App\Domain\Delivery\Health\Probes\SigningMaterialProbe;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Install diagnostics for a fresh cPanel/shared-hosting deployment (docs/HANDOFF.md section 13,
 * issue #39). Run this once after the release bundle is unpacked and `.env` is filled in, and
 * again after any change to the account's PHP version or extension set.
 *
 * ## What this can verify, and what it can only proxy for
 *
 * Every check here runs as the CLI process `php artisan esign:doctor` itself is invoked under.
 * Two things a CLI script fundamentally cannot observe are called out explicitly rather than
 * silently assumed:
 *
 * - **The web PHP runtime.** WebPhpVersionProbe compares this CLI's version against
 *   `ESIGN_CPANEL_WEB_PHP_VERSION`, an operator-supplied value, not something read from the
 *   live vhost. See that class's doc comment.
 * - **Cron.** There is no reliable, portable way for a PHP process to read another account's
 *   crontab. Instead this reuses SchedulerHeartbeatProbe — the same check `/health/ready` uses
 *   — which answers "is something actually invoking `schedule:run` every minute?" by looking at
 *   a cache key that task refreshes. A `fail` here most often means cron is not configured, but
 *   it is inference from an effect, not a direct read of the crontab.
 *
 * Reuses four of the existing `/health/ready` probes verbatim (DatabaseProbe, MailProbe,
 * SigningMaterialProbe, SchedulerHeartbeatProbe) rather than re-implementing database
 * connectivity, mail transport, and seal material checks a second time.
 *
 * Never prints a secret: every ProbeResult message follows the same "safe to print" contract
 * documented in docs/operations/health.md, and the new probes here (PhpRuntimeProbe,
 * WebPhpVersionProbe, WritablePathsProbe, EnvironmentProbe, QueueConnectionProbe,
 * ResourceLimitsProbe) hold themselves to the same rule.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'esign:doctor';

    protected $description = 'Diagnose a cPanel/shared-hosting install: PHP runtime, permissions, database, queue, mail, and seal material.';

    public function handle(Container $container): int
    {
        $probes = [
            $container->make(PhpRuntimeProbe::class),
            $container->make(WebPhpVersionProbe::class),
            $container->make(WritablePathsProbe::class),
            $container->make(EnvironmentProbe::class),
            $container->make(DatabaseProbe::class),
            $container->make(QueueConnectionProbe::class),
            $container->make(ResourceLimitsProbe::class),
            $container->make(SchedulerHeartbeatProbe::class),
            $container->make(SigningMaterialProbe::class),
            $container->make(MailProbe::class),
        ];

        $results = array_map(static fn (HealthProbe $probe): ProbeResult => $probe->check(), $probes);

        $this->table(
            ['Check', 'Status', 'Message'],
            array_map(static fn (ProbeResult $result): array => [
                $result->name,
                strtoupper($result->status->value),
                $result->message,
            ], $results),
        );

        $worst = HealthStatus::worst(array_map(static fn (ProbeResult $result): HealthStatus => $result->status, $results));

        if ($worst === HealthStatus::Fail) {
            $this->newLine();
            $this->error('One or more checks failed. Fix the failures above before considering this install complete.');

            return self::FAILURE;
        }

        if ($worst === HealthStatus::Warn) {
            $this->newLine();
            $this->comment('No failures, but one or more checks warned. Review the warnings above.');
        } else {
            $this->newLine();
            $this->info('All checks passed.');
        }

        return self::SUCCESS;
    }
}
