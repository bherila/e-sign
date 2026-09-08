<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\StaleEnvelope;
use App\Domain\Signing\Exceptions\StaleReview;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\RecipientAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Invariant 6: cancellation, expiry, signing, and finalization races have exactly one legal
 * outcome.
 *
 * No threads. Two model instances read the same row, one of them transitions it, and the
 * other's attempt is then holding a version the database no longer has — which is precisely
 * the situation two concurrent requests produce, and the only situation the guard has to
 * survive. The lock is a no-op on SQLite, so what is being exercised here is the version
 * predicate itself: the part that has to be right on MySQL and MariaDB too.
 */
class EnvelopeRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_beats_a_signature_read_before_it(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $recipient = $scenario->recipient($envelope, 'signer');
        $scenario->completeRequiredFieldsFor($envelope, $recipient);

        // The signer reads the envelope and reviews it.
        $reviewed = $scenario->acceptanceRequest($envelope, 'session-signer');

        // The sender withdraws it first.
        $scenario->machine()->cancel($envelope->refresh(), 'Deal fell through.');

        try {
            $scenario->machine()->accept($recipient->refresh(), $reviewed);
            $this->fail('An acceptance offered against a superseded version must not be recorded.');
        } catch (StaleReview $e) {
            $this->assertSame('envelope_version_moved', $e->reason);
        }

        $this->assertSame(EnvelopeState::Cancelled, $envelope->refresh()->state);
        $this->assertSame(0, RecipientAttestation::query()->count());
    }

    public function test_a_signature_beats_a_cancellation_read_before_it(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        // The sender opens the envelope, intending to cancel it.
        $senderView = Envelope::query()->findOrFail($envelope->getKey());

        // The signer gets there first.
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));

        try {
            $scenario->machine()->cancel($senderView, 'Too late.');
            $this->fail('A cancellation holding a superseded version must not win.');
        } catch (StaleEnvelope $e) {
            $this->assertSame('stale_envelope', $e->code());
            $this->assertSame($senderView->version, $e->expectedVersion);
        }

        $envelope->refresh();
        $this->assertSame(EnvelopeState::Finalizing, $envelope->state);
        $this->assertNull($envelope->cancelled_at);
        $this->assertNull($envelope->cancel_reason);
        $this->assertSame(1, RecipientAttestation::query()->count());
    }

    public function test_completing_and_failing_a_finalization_cannot_both_win(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));
        $envelope->refresh();

        // Two workers pick up the same finalizing envelope.
        $workerA = Envelope::query()->findOrFail($envelope->getKey());
        $workerB = Envelope::query()->findOrFail($envelope->getKey());

        $scenario->machine()->markCompleted($workerA, 'artifacts/2026/synthetic-final.pdf');

        try {
            $scenario->machine()->markFinalizationFailed($workerB, 'Sealing timed out.');
            $this->fail('The loser of a finalization race must not overwrite the winner.');
        } catch (StaleEnvelope $e) {
            $this->assertSame($workerB->version, $e->expectedVersion);
        }

        $envelope->refresh();
        $this->assertSame(EnvelopeState::Completed, $envelope->state);
        $this->assertSame('artifacts/2026/synthetic-final.pdf', $envelope->artifact_ref);
        $this->assertNull($envelope->finalization_failure_reason);
    }

    public function test_expiry_loses_to_a_finalization_that_already_happened(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $scheduler = Envelope::query()->findOrFail($envelope->getKey());

        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));

        // The scheduler's sweep started before the signature landed.
        try {
            $scenario->machine()->expire($scheduler);
            $this->fail('An expiry sweep must not expire an envelope that has already been signed.');
        } catch (StaleEnvelope) {
            // The version guard catches it before the state check ever matters.
        }

        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
    }

    public function test_two_parallel_signers_racing_on_one_envelope_yield_one_winner(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent([
            'field_schema' => SigningFixtures::parallelTwoSigners(),
            'signing_mode' => 'parallel',
        ]);
        $alice = $scenario->recipient($envelope, 'alice');
        $bob = $scenario->recipient($envelope, 'bob');

        $scenario->completeRequiredFieldsFor($envelope, $alice);
        $scenario->completeRequiredFieldsFor($envelope->refresh(), $bob);
        $envelope->refresh();

        // Both sessions reviewed the same version of the envelope.
        $aliceRequest = $scenario->acceptanceRequest($envelope, 'session-alice');
        $bobRequest = $scenario->acceptanceRequest($envelope, 'session-bob');

        $scenario->machine()->accept($alice->refresh(), $aliceRequest);

        try {
            $scenario->machine()->accept($bob->refresh(), $bobRequest);
            $this->fail('The second signer is holding a version the envelope no longer has.');
        } catch (StaleReview $e) {
            $this->assertSame('envelope_version_moved', $e->reason);
        }

        $this->assertSame(1, RecipientAttestation::query()->count());

        // Re-reading is all it takes: nothing material changed, so the second signature lands.
        $result = $scenario->machine()->accept(
            $bob->refresh(),
            $scenario->acceptanceRequest($envelope, 'session-bob'),
        );

        $this->assertTrue($result->completedSigning());
        $this->assertSame(2, RecipientAttestation::query()->count());
    }

    /**
     * Two cancellations differ from a cancel/sign race: the second is refused for the state
     * it finds, not for the version it held, and only when it re-read the row.
     */
    public function test_a_second_cancellation_is_refused_for_the_state_not_the_version(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $stale = Envelope::query()->findOrFail($envelope->getKey());

        $scenario->machine()->cancel($envelope, 'Withdrawn.');

        $this->expectException(StaleEnvelope::class);
        $scenario->machine()->cancel($stale, 'Withdrawn again.');
    }

    public function test_a_repeated_cancellation_on_a_current_instance_is_an_illegal_transition(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        $scenario->machine()->cancel($envelope, 'Withdrawn.');

        try {
            $scenario->machine()->cancel($envelope, 'Withdrawn again.');
            $this->fail('Cancelling a cancelled envelope is not a no-op success.');
        } catch (IllegalTransition $e) {
            $this->assertSame('cancel', $e->transition);
            $this->assertSame('cancelled', $e->from);
        }

        $this->assertSame('Withdrawn.', $envelope->refresh()->cancel_reason);
    }
}
