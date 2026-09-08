<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Events\CompositeEnvelopeEventSink;
use App\Domain\Delivery\Events\Console\ExpireCommand;
use App\Domain\Delivery\Events\Console\RemindCommand;
use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\PlaceholderSigningUrlMinter;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Events\UnconfiguredDownloadUrlMinter;
use App\Domain\Delivery\Mail\Console\MailBacklogCommand;
use App\Domain\Delivery\Mail\Console\ResendOutboundMailCommand;
use App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SnsMessageVerifier;
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
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\AuditEnvelopeEventSink;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Delivery module: the shared outbound destination policy, the
 * webhook outbox, and the transactional mail outbox.
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

        /*
         * The SES mail-feedback endpoint fails closed.
         *
         * TODO(#35): bind a verifier backed by `aws/aws-sns-message-validator` — the
         * package is not a dependency, and `aws-sdk-php`, which is present only
         * transitively through league/flysystem-aws-s3-v3, does not include the validator.
         * Until then every SNS message is refused, so no unverified body can mark a
         * message delivered or bounced. RejectingSnsMessageVerifier documents exactly what
         * implementing this involves.
         */
        $this->app->bind(SnsMessageVerifier::class, RejectingSnsMessageVerifier::class);

        /*
         * Invitations fail loudly until guest access binds a real minter.
         *
         * PlaceholderSigningUrlMinter throws rather than returning a plausible link, so an
         * install that starts sending envelopes before issue #36 lands gets a failed job it
         * can see instead of a mailbox full of dead links it cannot recall. The download
         * minter is the opposite and returns null, because a completion notice without a
         * link is still true (App\Mail\CompletedMail).
         */
        $this->app->bind(SigningUrlMinter::class, PlaceholderSigningUrlMinter::class);
        $this->app->bind(DownloadUrlMinter::class, UnconfiguredDownloadUrlMinter::class);
    }

    public function boot(): void
    {
        /*
         * Envelope transitions publish to the audit store *and* to the webhook outbox.
         *
         * Composed here rather than in SigningServiceProvider because the direction of the
         * dependency runs this way: Delivery implements a Signing port, and Signing must not
         * learn about webhooks to bind its own default. It is done in boot() rather than
         * register() for a duller reason — bootstrap/providers.php registers Signing after
         * Delivery, so a binding made in register() would be overwritten by Signing's own.
         *
         * Neither sink replaces the other. The audit store is the local history that survives
         * an endpoint being disabled; the outbox is what a receiver subscribes to.
         */
        $this->app->bind(EnvelopeEventSink::class, fn (Application $app): EnvelopeEventSink => new CompositeEnvelopeEventSink(
            $app->make(AuditEnvelopeEventSink::class),
            $app->make(DeliveryEnvelopeEventSink::class),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([
                EndpointCreateCommand::class,
                EndpointRotateSecretCommand::class,
                EndpointDisableCommand::class,
                EndpointEnableCommand::class,
                EndpointListCommand::class,
                ReplayCommand::class,
                BacklogCommand::class,
                ResendOutboundMailCommand::class,
                MailBacklogCommand::class,
                RemindCommand::class,
                ExpireCommand::class,
            ]);
        }
    }
}
