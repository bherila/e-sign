<?php

declare(strict_types=1);

namespace Tests\Support\SyntheticConsumer;

use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;
use Closure;
use Tests\Support\RecordingArtifactStore;

/**
 * The application's real sink, with a shared log around it and an optional interruption.
 *
 * Two things this suite needs cannot be seen from the end state.
 *
 * **Ordering.** "A completion event is published only after the final PDF is generated,
 * validated, durably stored, and retrievable" is a statement about sequence. Sharing one log
 * with {@see RecordingArtifactStore} makes the sequence itself assertable,
 * rather than the fact that both eventually happened.
 *
 * **A killed worker.** `$throwOn` throws *before* delegating, so the publishing transaction
 * rolls back with the artifact rows uninserted and no outbox event recorded, while the objects
 * uploaded in the previous step stay exactly where they are. Durable bytes, no rows, nothing on
 * anyone's wire: the same observable situation as a SIGKILL, and reachable from a test.
 */
final class ObservingEnvelopeEventSink implements EnvelopeEventSink
{
    /** @var list<string> */
    public array $log;

    /**
     * @param  list<string>  $log  Shared by reference with the artifact store.
     * @param  Closure(Envelope, EnvelopeEvent): void|null  $throwOn  Called before delegating.
     */
    public function __construct(
        private readonly EnvelopeEventSink $inner,
        array &$log = [],
        private readonly ?EnvelopeEvent $interruptOn = null,
        private readonly ?Closure $onInterrupt = null,
    ) {
        $this->log = &$log;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        if ($this->interruptOn === $event) {
            $this->log[] = 'interrupted:'.$event->value;

            if ($this->onInterrupt instanceof Closure) {
                ($this->onInterrupt)($envelope, $event);
            }

            throw new WorkerInterrupted(
                'Simulated worker termination while publishing '.$event->value.'.',
            );
        }

        $this->log[] = 'event:'.$event->value;

        $this->inner->record($envelope, $event, $payload);
    }
}
