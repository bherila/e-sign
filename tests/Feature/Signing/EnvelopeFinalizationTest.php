<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\MissingArtifactReference;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The boundary with the finalizer (issue #28).
 *
 * This module cannot see the bytes, so it enforces the part it can: completion needs a
 * reference to the artifact that makes it true, a failure stays visibly failed, and the
 * completion event is published from `markCompleted()` and nowhere else.
 */
class EnvelopeFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_requires_an_artifact_reference(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizing($scenario);

        foreach (['', '   '] as $empty) {
            try {
                $scenario->machine()->markCompleted($envelope, $empty);
                $this->fail('A completed envelope is not merely a status row.');
            } catch (MissingArtifactReference $e) {
                $this->assertSame('missing_artifact_reference', $e->code());
            }
        }

        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
        $this->assertNotContains(EnvelopeEvent::Completed->value, $scenario->sink->names());
    }

    public function test_an_over_long_artifact_reference_is_refused_rather_than_truncated(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizing($scenario);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/resolves to nothing/');

        $scenario->machine()->markCompleted(
            $envelope,
            str_repeat('a', EnvelopeStateMachine::MAX_ARTIFACT_REF_LENGTH + 1),
        );
    }

    public function test_completing_records_the_artifact_and_publishes_the_completion_event(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizing($scenario);

        $result = $scenario->machine()->markCompleted($envelope, ' artifacts/2026/synthetic-final.pdf ');

        $this->assertSame(EnvelopeState::Finalizing, $result->from);
        $this->assertSame(EnvelopeState::Completed, $result->to);
        $this->assertSame([EnvelopeEvent::Completed->value], $result->eventNames());
        $this->assertSame('artifacts/2026/synthetic-final.pdf', $envelope->artifact_ref);
        $this->assertNotNull($envelope->completed_at);
        $this->assertSame(
            'artifacts/2026/synthetic-final.pdf',
            $scenario->sink->payloadFor(EnvelopeEvent::Completed)['artifact_ref'],
        );
    }

    public function test_a_failed_finalization_stays_failed_and_never_looks_completed(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizing($scenario);

        $result = $scenario->machine()->markFinalizationFailed($envelope, 'The timestamp authority was unreachable.');

        $this->assertSame(EnvelopeState::FinalizationFailed, $result->to);
        $this->assertSame([EnvelopeEvent::FinalizationFailed->value], $result->eventNames());
        $this->assertSame('The timestamp authority was unreachable.', $envelope->finalization_failure_reason);
        $this->assertNull($envelope->completed_at);
        $this->assertNull($envelope->artifact_ref);
        $this->assertFalse($envelope->state->isTerminal());
    }

    public function test_a_failed_finalization_can_be_retried_and_then_completed(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizing($scenario);
        $scenario->machine()->markFinalizationFailed($envelope, 'Sealing timed out.');

        $retry = $scenario->machine()->retryFinalization($envelope);

        $this->assertSame(EnvelopeState::Finalizing, $retry->to);
        $this->assertSame([], $retry->eventNames());
        $this->assertNull($envelope->finalization_failure_reason);

        $scenario->machine()->markCompleted($envelope, 'artifacts/2026/synthetic-final.pdf');

        $this->assertSame(EnvelopeState::Completed, $envelope->refresh()->state);
    }

    public function test_the_acceptances_survive_a_failed_finalization_untouched(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizing($scenario);
        $before = $envelope->attestations()->get()->pluck('attestation_sha256')->all();

        $scenario->machine()->markFinalizationFailed($envelope, 'Sealing timed out.');
        $scenario->machine()->retryFinalization($envelope);

        $this->assertSame($before, $envelope->refresh()->attestations()->get()->pluck('attestation_sha256')->all());
    }

    private function finalizing(SigningScenario $scenario): Envelope
    {
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));

        return $envelope->refresh();
    }
}
