<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Mail\Console\MailBacklogCommand;
use App\Domain\Delivery\Mail\Console\ResendOutboundMailCommand;
use App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SnsMessageVerifier;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Delivery module's mail outbox.
 *
 * The commands are registered here rather than in `bootstrap/app.php`'s `withCommands()`
 * because they live under `app/Domain/Delivery/Mail/Console`, which Laravel's
 * `app/Console/Commands` auto-discovery does not scan, and because a module that owns
 * console entry points should be the thing that declares them.
 */
class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The SES feedback endpoint fails closed.
         *
         * TODO(#35): bind a verifier backed by `aws/aws-sns-message-validator` — the
         * package is not a dependency and `aws-sdk-php`, which is present only transitively
         * through league/flysystem-aws-s3-v3, does not include the validator. Until then
         * every SNS message is refused, so no unverified body can mark a message delivered
         * or bounced. RejectingSnsMessageVerifier documents exactly what implementing this
         * involves.
         */
        $this->app->bind(SnsMessageVerifier::class, RejectingSnsMessageVerifier::class);

        $this->commands([
            ResendOutboundMailCommand::class,
            MailBacklogCommand::class,
        ]);
    }
}
