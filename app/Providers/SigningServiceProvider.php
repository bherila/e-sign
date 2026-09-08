<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Signing\Assurance\ConfiguredSealAssurancePolicyCheck;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\AuditEnvelopeEventSink;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Signing module's two ports.
 *
 * Both defaults are deliberate rather than placeholders:
 *
 * - {@see AuditEnvelopeEventSink} writes each transition to the append-only
 *   `esign_audit_events` store, inside the transaction that made it. It is the module's own
 *   default and stays correct with nothing else installed. Where the Delivery module is
 *   present, `App\Providers\DeliveryServiceProvider` composes this sink with the webhook
 *   outbox instead of replacing it — an event that exists only as a webhook delivery leaves
 *   no local history the moment an endpoint is disabled.
 * - {@see ConfiguredSealAssurancePolicyCheck} answers from the seal configuration the sealer
 *   itself reads, so an install with no seal material cannot send. There is deliberately no
 *   binding that declares an assurance level available without the material behind it
 *   (docs/HANDOFF.md section 9).
 *
 * Both are bound, not singletons: the sink and the check are cheap, and a test that swaps
 * one mid-request should not have to fight a resolved instance.
 */
final class SigningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnvelopeEventSink::class, AuditEnvelopeEventSink::class);

        $this->app->bind(
            AssurancePolicyCheck::class,
            fn (Application $app): AssurancePolicyCheck => new ConfiguredSealAssurancePolicyCheck(
                $app->make('config'),
            ),
        );
    }
}
