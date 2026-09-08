<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Events\CompositeEnvelopeEventSink;
use App\Domain\Delivery\Events\Console\ExpireCommand;
use App\Domain\Delivery\Events\Console\RemindCommand;
use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\InvitationSigningUrlMinter;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Events\UnconfiguredDownloadUrlMinter;
use App\Domain\Delivery\Mail\Console\MailBacklogCommand;
use App\Domain\Delivery\Mail\Console\ResendOutboundMailCommand;
use App\Domain\Delivery\Mail\Feedback\AwsSnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\MailFeedbackRecorder;
use App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SesEventMapper;
use App\Domain\Delivery\Mail\Feedback\SesFeedbackProcessor;
use App\Domain\Delivery\Mail\Feedback\SesFeedbackRefusals;
use App\Domain\Delivery\Mail\Feedback\SesFeedbackSettings;
use App\Domain\Delivery\Mail\Feedback\SnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SnsSigningCertificates;
use App\Domain\Delivery\Mail\Feedback\SnsTopicAllowlist;
use App\Domain\Delivery\Mail\MailErrorRedactor;
use App\Domain\Delivery\Outbound\DestinationAllowlist;
use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\HostResolver;
use App\Domain\Delivery\Outbound\SystemHostResolver;
use App\Domain\Delivery\Queue\Console\WorkBoundedCommand;
use App\Domain\Delivery\Webhooks\Console\BacklogCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointCreateCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointDisableCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointEnableCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointListCommand;
use App\Domain\Delivery\Webhooks\Console\EndpointRotateSecretCommand;
use App\Domain\Delivery\Webhooks\Console\ReplayCommand;
use App\Domain\Delivery\Webhooks\RetrySchedule;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Evidence\Retention\RestoreDrill;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\AuditEnvelopeEventSink;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
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
                restoreDrill: $app->make(RestoreDrill::class),
                autoDisableAfter: max(1, (int) ($config['auto_disable_after'] ?? 10)),
                queue: isset($config['queue']) ? (string) $config['queue'] : null,
            );
        });

        $this->app->singleton(
            SesFeedbackSettings::class,
            function (Application $app): SesFeedbackSettings {
                /** @var array<string, mixed> $config */
                $config = $app->make('config')->get('esign.mail.ses', []);

                return SesFeedbackSettings::fromConfig($config);
            }
        );

        $this->app->singleton(SnsTopicAllowlist::class, fn (Application $app): SnsTopicAllowlist => SnsTopicAllowlist::fromConfig(
            $app->make('config')->get('esign.mail.ses.topic_arns', [])
        ));

        $this->app->singleton(SesFeedbackRefusals::class, fn (Application $app): SesFeedbackRefusals => new SesFeedbackRefusals(
            cache: $app->make('cache.store'),
            redactor: $app->make(MailErrorRedactor::class),
            settings: $app->make(SesFeedbackSettings::class),
        ));

        $this->app->singleton(SnsSigningCertificates::class, fn (Application $app): SnsSigningCertificates => new SnsSigningCertificates(
            http: $app->make(HttpFactory::class),
            destinations: $app->make(DestinationPolicy::class),
            cache: $app->make('cache.store'),
            ttlSeconds: $app->make(SesFeedbackSettings::class)->certificateCacheTtlSeconds,
        ));

        $this->app->bind(SesFeedbackProcessor::class, fn (Application $app): SesFeedbackProcessor => new SesFeedbackProcessor(
            mapper: $app->make(SesEventMapper::class),
            recorder: $app->make(MailFeedbackRecorder::class),
            http: $app->make(HttpFactory::class),
            destinations: $app->make(DestinationPolicy::class),
            settings: $app->make(SesFeedbackSettings::class),
        ));

        /*
         * The SES mail-feedback endpoint, and the one place the decision to accept SES
         * feedback at all is made (issue #35).
         *
         * With no topic in `esign.mail.ses.topic_arns` this binds
         * RejectingSnsMessageVerifier and the endpoint refuses everything. That is not a
         * formality: a valid AWS signature proves only that *some* AWS customer signed the
         * message, and with no allowlist there is no answer to "is this our topic?". An
         * unconfigured deployment therefore fails closed rather than degrading to
         * signature-only, and it does so by binding a different class, so the refusal is
         * visible in the container instead of buried in a conditional.
         *
         * With a topic configured, AwsSnsMessageVerifier does the real work over
         * `aws/aws-php-sns-message-validator`: AWS's own canonical string-to-sign for each
         * of the three message types, the certificate fetched through the shared
         * DestinationPolicy and cached, SHA-1 signatures refused unless enabled, and a
         * replay window on the timestamp.
         */
        $this->app->bind(SnsMessageVerifier::class, function (Application $app): SnsMessageVerifier {
            $topics = $app->make(SnsTopicAllowlist::class);

            if (! $topics->isConfigured()) {
                return new RejectingSnsMessageVerifier;
            }

            return new AwsSnsMessageVerifier(
                topics: $topics,
                certificates: $app->make(SnsSigningCertificates::class),
                settings: $app->make(SesFeedbackSettings::class),
            );
        });

        /*
         * Signing links are one-shot invitations issued by the Signing module. Every mint
         * revokes the recipient's previous live link, so a reminder always carries a working
         * one and the earlier one is dead. Any failure surfaces as SigningUrlUnavailable and
         * the scheduling job fails visibly; nothing is ever sent with a placeholder link.
         */
        $this->app->bind(SigningUrlMinter::class, InvitationSigningUrlMinter::class);
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
                WorkBoundedCommand::class,
            ]);
        }
    }
}
