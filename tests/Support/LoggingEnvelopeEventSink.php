<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;
use RuntimeException;

/**
 * An event sink that appends to a shared log, and can be told to throw on one event.
 *
 * The log is the same array {@see RecordingArtifactStore} writes to, so a test can assert that
 * the completion event came *after* the artifact bytes were read back rather than merely that
 * both happened.
 *
 * Throwing on `signing_request.completed` is how the suite reproduces a worker that dies in
 * the publishing transaction: the artifact rows are inserted and then rolled back, while the
 * objects uploaded in the previous step stay exactly where they are. That is the same
 * observable situation as a killed process — durable bytes, no rows — and it is reachable
 * from a test, which a real SIGKILL is not.
 */
final class LoggingEnvelopeEventSink implements EnvelopeEventSink
{
    /** @var list<string> */
    public array $log;

    /**
     * @param  list<string>  $log
     */
    public function __construct(array &$log = [], private readonly ?EnvelopeEvent $throwOn = null)
    {
        $this->log = &$log;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        $this->log[] = 'event:'.$event->value;

        if ($this->throwOn === $event) {
            throw new RuntimeException('Simulated worker failure while publishing.');
        }
    }
}
