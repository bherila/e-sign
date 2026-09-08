<?php

declare(strict_types=1);

namespace App\Domain\Signing\Envelopes;

use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Models\Envelope;

/**
 * A sink that records nothing.
 *
 * Not the default binding. It exists so a unit test can exercise a transition without a
 * trail, and so a caller composing sinks has a neutral element. A deployment that selects
 * it has chosen to keep no envelope event history, which is a decision, not a default.
 */
final class NullEnvelopeEventSink implements EnvelopeEventSink
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void {}
}
