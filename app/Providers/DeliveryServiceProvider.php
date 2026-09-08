<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Outbound\DestinationAllowlist;
use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\HostResolver;
use App\Domain\Delivery\Outbound\SystemHostResolver;
use App\Domain\Delivery\Webhooks\Console\BacklogCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointCreateCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointDisableCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointEnableCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointListCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointRotateSecretCommand;
use App\Domain\Delivery\Webhooks\Console\ReplayCommand;
use App\Domain\Delivery\Webhooks\RetrySchedule;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Identity\Audit\AuditRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Delivery module: the shared outbound destination policy and the
 * webhook outbox.
 *
 * The destination policy is a singleton because its allowlist is deployment
 * configuration; the Evidence module resolves the same instance for the
 * timestamp authority, so an operator configures an internal destination in one
 * place and both transports honour it.
 *
 * The console commands are registered here rather than in bootstrap/app.php
 * because Laravel's command auto-discovery only scans app/Console/Commands and
 * never looks inside a domain module.
 */
final class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostResolver::class, SystemHostResolver::class);

        $this->app->singleton(DestinationPolicy::class, function (Application $app): DestinationPolicy {
            /** @var iterable<mixed> $allowlist */
            $allowlist = $app->make('config')->get('esign.delivery.destination_allowlist', []);

            return new DestinationPolicy(
                resolver: $app->make(HostResolver::class),
                allowlist: DestinationAllowlist::fromConfig($allowlist),
            );
        });

        $this->app->singleton(WebhookDispatcher::class, function (Application $app): WebhookDispatcher {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('esign.delivery.webhooks', []);

            return new WebhookDispatcher(
                schedule: RetrySchedule::fromConfig($config),
                audit: $app->make(AuditRecorder::class),
                autoDisableAfter: max(1, (int) ($config['auto_disable_after'] ?? 10)),
                queue: isset($config['queue']) ? (string) $config['queue'] : null,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                EndpointCreateCommand::class,
                EndpointRotateSecretCommand::class,
                EndpointDisableCommand::class,
                EndpointEnableCommand::class,
                EndpointListCommand::class,
                ReplayCommand::class,
                BacklogCommand::class,
            ]);
        }
    }
}
