<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\SigningException;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Every transition attempted from every state.
 *
 * The expectation is not written out a second time — it is read from
 * {@see EnvelopeStateMachine::TRANSITIONS}, and a separate test pins that table against the
 * table in docs/signing/state-machine.md. So a rule can be changed in exactly one place, and
 * changing it there without changing the documentation fails.
 */
class EnvelopeTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_illegal_transition_is_refused_from_every_state(): void
    {
        $attempted = 0;

        foreach (EnvelopeState::cases() as $state) {
            foreach (array_keys(EnvelopeStateMachine::TRANSITIONS) as $transition) {
                if (in_array($state->value, EnvelopeStateMachine::TRANSITIONS[$transition], true)) {
                    continue;
                }

                $scenario = SigningScenario::create();
                $envelope = $this->envelopeIn($scenario, $state);
                $attempted++;

                try {
                    $this->attempt($scenario, $envelope, $transition);
                    $this->fail(sprintf('"%s" must be illegal from "%s".', $transition, $state->value));
                } catch (IllegalTransition $e) {
                    $this->assertSame($transition, $e->transition);
                    $this->assertSame($state->value, $e->from);
                }

                $this->assertSame($state, $envelope->refresh()->state);
            }
        }

        // 9 states x 11 transitions, minus the 20 legal pairs.
        $this->assertSame(79, $attempted);
    }

    public function test_every_legal_transition_is_reachable(): void
    {
        foreach (EnvelopeStateMachine::TRANSITIONS as $transition => $states) {
            foreach ($states as $state) {
                $scenario = SigningScenario::create();
                $envelope = $this->envelopeIn($scenario, EnvelopeState::from($state));

                try {
                    $this->attempt($scenario, $envelope, $transition);
                } catch (IllegalTransition $e) {
                    $this->fail(sprintf(
                        '"%s" is declared legal from "%s" but was refused: %s',
                        $transition,
                        $state,
                        $e->getMessage(),
                    ));
                } catch (SigningException) {
                    // A legal transition may still be refused on its own merits — an
                    // acceptance from a pending recipient, a value the schema does not
                    // accept. Those are covered by the tests that set them up properly; what
                    // matters here is that the state itself was not the objection.
                }
            }
        }
    }

    public function test_the_documented_transition_table_matches_the_code(): void
    {
        $documented = [];
        $rows = file_get_contents(base_path('docs/signing/state-machine.md'));

        preg_match('/<!-- transitions:start -->(.*?)<!-- transitions:end -->/s', (string) $rows, $matches);
        $this->assertNotEmpty($matches, 'docs/signing/state-machine.md must delimit its transition table.');

        foreach (explode("\n", $matches[1]) as $line) {
            if (! str_starts_with(trim($line), '| `')) {
                continue;
            }

            $cells = array_map('trim', array_slice(explode('|', $line), 1, -1));
            $transition = trim($cells[0], ' `');
            $documented[$transition] = array_map(
                static fn (string $state): string => trim($state, ' `'),
                explode(', ', $cells[1]),
            );
        }

        $this->assertSame(EnvelopeStateMachine::TRANSITIONS, $documented);
    }

    private function envelopeIn(SigningScenario $scenario, EnvelopeState $state): Envelope
    {
        $envelope = match ($state) {
            EnvelopeState::Draft => $scenario->preparedDraft(),
            default => $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]),
        };

        $machine = $scenario->machine();

        switch ($state) {
            case EnvelopeState::Draft:
            case EnvelopeState::Sent:
                break;
            case EnvelopeState::InProgress:
                $machine->submitValues(
                    $scenario->recipient($envelope, 'signer'),
                    ['signer_signature' => 'Synthetic signature'],
                );
                break;
            case EnvelopeState::Cancelled:
                $machine->cancel($envelope, 'Withdrawn by the sender.');
                break;
            case EnvelopeState::Declined:
                $machine->decline($scenario->recipient($envelope, 'signer'), 'Not acceptable.');
                break;
            case EnvelopeState::Expired:
                CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));
                $machine->expire($envelope->refresh());
                CarbonImmutable::setTestNow();
                break;
            case EnvelopeState::Finalizing:
                $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));
                break;
            case EnvelopeState::Completed:
                $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));
                $machine->markCompleted($envelope->refresh(), 'artifacts/synthetic.pdf');
                break;
            case EnvelopeState::FinalizationFailed:
                $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));
                $machine->markFinalizationFailed($envelope->refresh(), 'The timestamp authority was unreachable.');
                break;
        }

        $envelope->refresh();
        $this->assertSame($state, $envelope->state, 'Failed to arrange an envelope in "'.$state->value.'".');

        return $envelope;
    }

    private function attempt(SigningScenario $scenario, Envelope $envelope, string $transition): void
    {
        $machine = $scenario->machine();
        $recipient = $envelope->recipients()->firstOrFail();

        match ($transition) {
            'send' => $machine->send($envelope),
            'submit_values' => $machine->submitValues($recipient, []),
            'set_sender_values' => $machine->setSenderValues($envelope, []),
            'accept' => $machine->accept($recipient, $scenario->acceptanceRequest($envelope, 'session-matrix')),
            'decline' => $machine->decline($recipient, 'No.'),
            'cancel' => $machine->cancel($envelope, 'Withdrawn.'),
            // Expiry is a fact about the clock: `expire()` refuses an envelope that is not
            // due, so reaching the state check at all means moving time forward first.
            'expire' => $this->atExpiry(fn () => $machine->expire($envelope)),
            'freeze_for_parallel' => $machine->freezeForParallel($envelope),
            'mark_completed' => $machine->markCompleted($envelope, 'artifacts/synthetic.pdf'),
            'mark_finalization_failed' => $machine->markFinalizationFailed($envelope, 'Synthetic failure.'),
            'retry_finalization' => $machine->retryFinalization($envelope),
        };
    }

    private function atExpiry(callable $work): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));

        try {
            $work();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
