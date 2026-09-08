<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\FinalizationBacklogProbe;
use App\Domain\Evidence\Finalization\FinalizationRun;
use App\Domain\Evidence\Finalization\FinalizationRunState;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The probe that notices an executed agreement nobody is producing (issue #94).
 *
 * Distinct from the `queue` probe. The failure this one catches is the queue-looks-fine one:
 * the finalization job is not late, it is *gone*, so the queue is empty and green while a
 * signer waits for a document that will never be sealed.
 */
class FinalizationBacklogProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('esign.finalization.resume_after_minutes', 10);
    }

    public function test_is_ok_when_nothing_is_finalizing(): void
    {
        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('No envelope has been waiting', $result->message);
    }

    public function test_is_ok_while_an_envelope_is_still_inside_the_window(): void
    {
        $this->finalizingEnvelope();

        $this->travel(5)->minutes();

        $this->assertSame(HealthStatus::Ok, $this->probe()->check()->status);
    }

    public function test_is_ok_while_a_worker_is_still_running(): void
    {
        $envelope = $this->finalizingEnvelope();

        $this->travel(30)->minutes();

        $this->runFor($envelope, FinalizationRunState::Rendered, CarbonImmutable::now()->subMinute());

        $this->assertSame(HealthStatus::Ok, $this->probe()->check()->status);
    }

    public function test_warns_as_soon_as_one_envelope_is_past_the_window(): void
    {
        $this->finalizingEnvelope();

        $this->travel(30)->minutes();

        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
        $this->assertStringContainsString('1 envelope(s)', $result->message);
        $this->assertStringContainsString('the oldest for 30 minute(s)', $result->message);
    }

    public function test_fails_once_more_than_ten_envelopes_are_waiting(): void
    {
        $scenario = SigningScenario::create();

        for ($i = 0; $i < 11; $i++) {
            $this->finalizingEnvelope($scenario, 'session-'.$i);
        }

        $this->travel(30)->minutes();

        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
        $this->assertStringContainsString('11 envelope(s)', $result->message);
    }

    /**
     * One envelope that has outlived five resume sweeps is a stopped worker, not a slow one.
     */
    public function test_fails_once_a_single_envelope_has_waited_an_hour(): void
    {
        $this->finalizingEnvelope();

        $this->travel(60)->minutes();

        $this->assertSame(HealthStatus::Fail, $this->probe()->check()->status);
    }

    public function test_the_message_never_names_an_envelope_or_its_title(): void
    {
        $envelope = $this->finalizingEnvelope();

        $this->travel(30)->minutes();

        $message = $this->probe()->check()->message;

        $this->assertStringNotContainsString($envelope->public_id, $message);
        $this->assertStringNotContainsString($envelope->title, $message);
    }

    public function test_the_probe_is_registered_on_the_readiness_endpoint(): void
    {
        config(['esign.health_token' => 'synthetic-health-token']);

        $this->withHeaders(['Authorization' => 'Bearer synthetic-health-token'])
            ->getJson('/health/ready')
            ->assertJsonStructure(['probes' => ['finalization_backlog' => ['status', 'message']]]);
    }

    private function probe(): FinalizationBacklogProbe
    {
        return app(FinalizationBacklogProbe::class);
    }

    private function finalizingEnvelope(?SigningScenario $scenario = null, string $sessionRef = 'session-1'): Envelope
    {
        $scenario ??= SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'), $sessionRef);

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
}
