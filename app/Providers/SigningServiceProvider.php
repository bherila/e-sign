<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Evidence\Contracts\PdfSealer;
use App\Domain\Signing\Assurance\ConfiguredSealAssurancePolicyCheck;
use App\Domain\Signing\Assurance\SealMaterialAssurancePolicyCheck;
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
 * - {@see SealMaterialAssurancePolicyCheck} answers from the seal configuration the sealer
 *   itself reads and then from the material behind it, so an install with no usable seal
 *   material cannot send. It layers the cheap configuration check
 *   ({@see ConfiguredSealAssurancePolicyCheck}, which gives an operator the precise message
 *   for an unfinished install) over the sealer's own preflight, which is what catches an
 *   expired certificate, a key that does not match it, and an unusable timestamp authority.
 *   There is deliberately no binding that declares an assurance level available without the
 *   material behind it (docs/HANDOFF.md section 9).
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
            fn (Application $app): AssurancePolicyCheck => new SealMaterialAssurancePolicyCheck(
                new ConfiguredSealAssurancePolicyCheck($app->make('config')),
                $app->make(PdfSealer::class),
            ),
        );
    }
}
