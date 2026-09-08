<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * What starts finalization, and when (issue #94).
 *
 * ## Why this suite does not fake the queue
 *
 * `Queue::fake()` records a dispatch the moment it is made and never consults the
 * transaction manager, so under it an in-transaction dispatch and an after-commit dispatch
 * look identical — and the difference is the entire property under test. The `database`
 * connection is a real queue whose rows a test can count and which honours `afterCommit()`
 * exactly as a deployment's does, so "is it on the queue yet" becomes a question with a
 * truthful answer at both points in time.
 *
 * The sink is the container's own, not a hand-built trigger: what has to hold is that a
 * deployment's wiring dispatches, and a test that constructed the trigger itself would pass
 * on an application that never bound it.
 */
class FinalizationTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'database');
    }

    public function test_the_last_acceptance_queues_exactly_one_finalization_after_the_commit(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        DB::transaction(function () use ($scenario, $envelope): void {
            $this->accept($scenario, $envelope, 'signer');

            // The transition is done and the envelope is `finalizing` — inside a transaction
            // that has not committed. A worker that could see the job now could start
            // sealing a document nobody has finished signing.
            $this->assertSame(
                EnvelopeState::Finalizing,
                Envelope::query()->findOrFail($envelope->getKey())->state,
            );
            $this->assertSame([], $this->queuedFinalizations());
        });

        $this->assertSame([$envelope->public_id], $this->queuedFinalizations());
    }

    public function test_a_rolled_back_acceptance_queues_nothing(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        try {
            DB::transaction(function () use ($scenario, $envelope): void {
                $this->accept($scenario, $envelope, 'signer');

                throw new RuntimeException('The caller failed after the transition.');
            });
            $this->fail('The transaction should have been rolled back.');
        } catch (RuntimeException) {
            // Expected.
        }

        // Neither the state nor the work: a finalization queued here would look for an
        // envelope that is not `finalizing` and fail for a reason that never happened.
        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
        $this->assertSame([], $this->queuedFinalizations());
    }

    public function test_an_earlier_acceptance_queues_nothing_and_only_the_last_one_does(): void
    {
        $scenario = SigningScenario::create();
        // The default fixture is two sequential signers, so the first acceptance leaves the
        // envelope `in_progress` with one signature outstanding.
        $envelope = $scenario->sent();

        $this->accept($scenario, $envelope, 'buyer', 'session-buyer');

        $this->assertSame(EnvelopeState::InProgress, $envelope->refresh()->state);
        $this->assertSame([], $this->queuedFinalizations());

        $this->accept($scenario, $envelope, 'seller', 'session-seller');

        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
        $this->assertSame([$envelope->public_id], $this->queuedFinalizations());
    }

    public function test_no_other_transition_queues_a_finalization(): void
    {
        $scenario = SigningScenario::create();
        $machine = $this->machine($scenario);

        // Creation and send, then a decline, then a cancellation: every remaining event the
        // sink is called with, on envelopes nobody finished signing.
        $declined = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $machine->decline($scenario->recipient($declined, 'signer'), 'No.');

        $cancelled = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $machine->cancel($cancelled, 'Withdrawn.');

        $this->assertSame([], $this->queuedFinalizations());
    }

    /**
     * A failed finalization is an operator's decision, not a transition's.
     *
     * `retryFinalization()` puts the envelope back in `finalizing` and publishes no event at
     * all, which is what keeps `esign:finalization:resume` and this trigger from turning one
     * legible failure into a loop of them. The re-dispatch is the operator's, deliberately.
     */
    public function test_retrying_a_failed_finalization_queues_nothing_by_itself(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $machine = $this->machine($scenario);

        $this->accept($scenario, $envelope, 'signer');
        $machine->markFinalizationFailed($envelope->refresh(), 'Sealing timed out.');

        DB::table('jobs')->delete();

        $machine->retryFinalization($envelope->refresh());

        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
        $this->assertSame([], $this->queuedFinalizations());
    }

    private function accept(
        SigningScenario $scenario,
        Envelope $envelope,
        string $schemaRecipientId,
        string $sessionRef = 'session-1',
    ): void {
        $recipient = $scenario->recipient($envelope, $schemaRecipientId);
        $scenario->completeRequiredFieldsFor($envelope, $recipient);

        $this->machine($scenario)->accept(
            $recipient->refresh(),
            $scenario->acceptanceRequest($envelope, $sessionRef),
        );
    }

    private function machine(SigningScenario $scenario): EnvelopeStateMachine
    {
        return new EnvelopeStateMachine(app(EnvelopeEventSink::class), $scenario->assurance);
    }

    /**
     * The envelope ids of every queued finalization, in the order they were pushed.
     *
     * The `jobs` table also carries the mail scheduling the same transitions produce, so the
     * rows are filtered by job class rather than counted.
     *
     * @return list<string>
     */
    private function queuedFinalizations(): array
    {
        $ids = [];

        foreach (DB::table('jobs')->orderBy('id')->pluck('payload') as $payload) {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode((string) $payload, true);

            if (($decoded['displayName'] ?? null) !== FinalizeEnvelope::class) {
                continue;
            }

            /** @var array{command: string} $data */
            $data = $decoded['data'];
            $job = unserialize($data['command']);

            $this->assertInstanceOf(FinalizeEnvelope::class, $job);

            $ids[] = $job->envelopePublicId;
        }

        return $ids;
    }
}
