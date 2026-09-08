<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Finalization\FinalizationRun;
use App\Domain\Evidence\Finalization\FinalizationRunState;
use App\Domain\Evidence\Finalization\Jobs\FinalizeEnvelope;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The safety net under the trigger: `esign:finalization:resume` (issue #94).
 *
 * The trigger dispatches once, after the last acceptance commits. Nothing else ever happens
 * to an envelope in `finalizing`, so if that job is lost — a worker killed before it started,
 * a `jobs` table restored from a backup, a purged queue — no later transition will notice.
 * This command is the only thing that does, and the interesting half of it is everything it
 * refuses to touch.
 */
class FinalizationResumeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([FinalizeEnvelope::class]);
        config()->set('esign.finalization.resume_after_minutes', 10);
    }

    public function test_an_envelope_stuck_in_finalizing_with_no_run_is_re_dispatched(): void
    {
        $envelope = $this->finalizingEnvelope();

        $this->travel(30)->minutes();

        $this->artisan('esign:finalization:resume')->assertSuccessful();

        $this->assertDispatchedFor([$envelope->public_id]);
    }

    public function test_an_envelope_whose_run_is_older_than_the_window_is_re_dispatched(): void
    {
        $envelope = $this->finalizingEnvelope();
        $this->runFor($envelope, FinalizationRunState::Started, CarbonImmutable::now());

        $this->travel(30)->minutes();

        $this->artisan('esign:finalization:resume')->assertSuccessful();

        $this->assertDispatchedFor([$envelope->public_id]);
    }

    /**
     * Sealing is slow, and interrupting it only allocates a competing generation.
     */
    public function test_an_envelope_whose_run_started_inside_the_window_is_left_alone(): void
    {
        $envelope = $this->finalizingEnvelope();

        $this->travel(30)->minutes();

        // A worker picked it up a minute ago and is still going.
        $this->runFor($envelope, FinalizationRunState::Rendered, CarbonImmutable::now()->subMinute());

        $this->artisan('esign:finalization:resume')->assertSuccessful();

        $this->assertDispatchedFor([]);
    }

    public function test_nothing_is_re_dispatched_before_the_window_has_passed(): void
    {
        $this->finalizingEnvelope();

        $this->travel(5)->minutes();

        $this->artisan('esign:finalization:resume')
            ->expectsOutputToContain('No envelope has been waiting to finalize')
            ->assertSuccessful();

        $this->assertDispatchedFor([]);
    }

    public function test_a_completed_envelope_is_never_re_dispatched(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizingEnvelope($scenario);
        $this->runFor($envelope, FinalizationRunState::Published, CarbonImmutable::now());

        $scenario->machine()->markCompleted($envelope->refresh(), 'artifacts/synthetic-executed.pdf');

        $this->travel(30)->minutes();

        $this->artisan('esign:finalization:resume')->assertSuccessful();

        $this->assertSame(EnvelopeState::Completed, $envelope->refresh()->state);
        $this->assertDispatchedFor([]);
    }

    /**
     * `finalization_failed` is a visible state an operator acts on, by design.
     *
     * Sweeping it here would retry a failure every five minutes forever, and each attempt
     * would record another run and another `esign.envelope.finalization.failed` on the wire.
     * `docs/signing/state-machine.md`: the retry is `retryFinalization()` and an explicit
     * dispatch.
     */
    public function test_a_failed_finalization_is_left_for_the_operator(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $this->finalizingEnvelope($scenario);
        $this->runFor($envelope, FinalizationRunState::Failed, CarbonImmutable::now());

        $scenario->machine()->markFinalizationFailed($envelope->refresh(), 'Sealing timed out.');

        $this->travel(30)->minutes();

        $this->artisan('esign:finalization:resume')->assertSuccessful();

        $this->assertSame(EnvelopeState::FinalizationFailed, $envelope->refresh()->state);
        $this->assertDispatchedFor([]);
    }

    public function test_it_reports_what_it_re_dispatched(): void
    {
        $this->finalizingEnvelope();
        $this->finalizingEnvelope();

        $this->travel(30)->minutes();

        $this->artisan('esign:finalization:resume')
            ->expectsOutputToContain('Re-dispatched finalization for 2 envelope(s).')
            ->assertSuccessful();
    }

    /**
     * An envelope everybody has signed, with no finalization started.
     *
     * The scenario's own recording sink is used deliberately: the container's composite would
     * fire the real trigger and queue the very job this command exists to notice the absence
     * of.
     */
    private function finalizingEnvelope(?SigningScenario $scenario = null): Envelope
    {
        $scenario ??= SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));

        $envelope->refresh();
        $this->assertSame(EnvelopeState::Finalizing, $envelope->state);

        return $envelope;
    }

    private function runFor(Envelope $envelope, FinalizationRunState $state, CarbonImmutable $startedAt): void
    {
        FinalizationRun::query()->create([
            'envelope_id' => $envelope->getKey(),
            'generation' => 1,
            'state' => $state,
            'input_snapshot' => ['snapshot_sha256' => str_repeat('0', 64)],
            'started_at' => $startedAt,
        ]);
    }

    /**
     * @param  list<string>  $envelopePublicIds
     */
    private function assertDispatchedFor(array $envelopePublicIds): void
    {
        $dispatched = Queue::pushed(FinalizeEnvelope::class)
            ->map(static fn (FinalizeEnvelope $job): string => $job->envelopePublicId)
            ->sort()
            ->values()
            ->all();

        sort($envelopePublicIds);

        $this->assertSame($envelopePublicIds, $dispatched);
    }
}
