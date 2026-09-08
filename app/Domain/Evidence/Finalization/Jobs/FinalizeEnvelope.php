<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Finalization\Jobs;

use App\Domain\Evidence\Finalization\EnvelopeFinalizer;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one finalization attempt on the queue.
 *
 * Queued because it is the only genuinely slow thing this application does — importing and
 * re-laying-out a document, sealing it, and possibly waiting on a timestamp authority — and
 * docs/HANDOFF.md section 13 is explicit that a single process must not mix web-request
 * latency with sealing work. In Docker the worker is also the only role the seal material is
 * mounted into, so this is the only place the private key is reachable at all.
 *
 * ## Why it does not retry itself
 *
 * `tries = 1`. A failed attempt leaves the envelope in `finalization_failed`, which is a
 * *visible* state an operator or an administrator acts on: retrying is
 * `EnvelopeStateMachine::retryFinalization()` followed by a fresh dispatch, and that pair is
 * deliberately an explicit decision rather than a queue-level default. An automatic retry
 * would find the envelope no longer `finalizing` and fail again for a different reason,
 * turning one legible failure into a run of confusing ones.
 *
 * Recovery from a *crash* is different from recovery from a failure and is handled inside
 * {@see EnvelopeFinalizer}: a worker killed between the upload and the publication leaves a
 * run in `uploaded`, and the next attempt republishes those exact bytes.
 *
 * ## Idempotence
 *
 * Dispatching this twice for the same envelope is safe. Each dispatch allocates its own
 * generation under the envelope lock, and the publishing transaction's compare-and-swap lets
 * exactly one of them publish (docs/ARCHITECTURE.md invariant 6). The loser records itself as
 * failed and leaves the envelope alone.
 *
 * The envelope is carried by its public id rather than as a serialized model: the row will
 * have moved by the time the job runs, and a job that deserialized a stale copy would be
 * making decisions from the world as it was when it was queued.
 */
final class FinalizeEnvelope implements ShouldQueue
{
    use Queueable;

    /** One attempt. See the class docblock. */
    public int $tries = 1;

    public function __construct(public readonly string $envelopePublicId) {}

    public function handle(EnvelopeFinalizer $finalizer): void
    {
        $envelope = Envelope::query()->where('public_id', $this->envelopePublicId)->firstOrFail();

        $finalizer->finalize($envelope);
    }

    /**
     * One in-flight attempt per envelope at a time, where the queue driver supports it.
     *
     * This is an optimisation, not the guarantee: `database` and `sync` drivers ignore it,
     * so correctness under concurrent attempts rests on the compare-and-swap in the
     * publishing transaction, which every driver gets.
     */
    public function uniqueId(): string
    {
        return $this->envelopePublicId;
    }
}
