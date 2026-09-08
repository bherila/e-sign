<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence\Retention;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\ArtifactIntegrityProbe;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Retention\ArtifactIntegrityVerifier;
use App\Domain\Evidence\Retention\ArtifactVerificationRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RetentionScenario;
use Tests\TestCase;

/**
 * Integrity verification: does the object still hash to what the row says, and does the
 * seal still validate over it?
 *
 * The tampering cases flip a byte and delete an object on the faked disk. That is a fair
 * model of what this check exists to catch, because the application has no other way to
 * distinguish bit rot, a half-restored bucket, and an object replaced out of band — in every
 * one of them the row is intact and the bytes are not what it describes.
 */
class ArtifactIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clean_corpus_verifies_and_records_the_run(): void
    {
        RetentionScenario::finalized();

        $this->artisan('esign:artifacts:verify')
            ->expectsOutputToContain('3 artifact(s) checked')
            ->assertSuccessful();

        $run = ArtifactVerificationRun::lastCompleted();

        $this->assertNotNull($run);
        $this->assertTrue($run->passed);
        $this->assertSame(3, $run->artifacts_checked);
        $this->assertSame(0, $run->problemCount());
        $this->assertNull($run->findings);
    }

    public function test_a_flipped_byte_is_a_digest_mismatch_and_a_non_zero_exit(): void
    {
        RetentionScenario::finalized();

        $artifact = Artifact::query()->where('kind', ArtifactKind::EvidenceJson->value)->sole();
        $bytes = (string) Storage::disk('documents')->get($artifact->path);
        $bytes[10] = $bytes[10] === 'x' ? 'y' : 'x';
        Storage::disk('documents')->put($artifact->path, $bytes);

        $this->artisan('esign:artifacts:verify')
            ->expectsOutputToContain('does not match the recorded')
            ->assertFailed();

        $run = ArtifactVerificationRun::lastCompleted();

        $this->assertFalse($run->passed);
        $this->assertSame(1, $run->digest_mismatches);
        $this->assertSame(0, $run->missing_objects);
        $this->assertSame($artifact->public_id, $run->findings[0]['artifact']);
        $this->assertSame('digest_mismatch', $run->findings[0]['problem']);
    }

    public function test_tampering_with_the_executed_pdf_is_caught(): void
    {
        RetentionScenario::finalized();

        $artifact = Artifact::query()->where('kind', ArtifactKind::ExecutedPdf->value)->sole();
        $bytes = (string) Storage::disk('documents')->get($artifact->path);
        $offset = intdiv(strlen($bytes), 2);
        $bytes[$offset] = $bytes[$offset] === 'A' ? 'B' : 'A';
        Storage::disk('documents')->put($artifact->path, $bytes);

        $this->artisan('esign:artifacts:verify')->assertFailed();

        $this->assertSame(1, ArtifactVerificationRun::lastCompleted()->digest_mismatches);
    }

    public function test_a_missing_object_is_reported_and_fails(): void
    {
        RetentionScenario::finalized();

        $artifact = Artifact::query()->where('kind', ArtifactKind::CompletionReport->value)->sole();
        Storage::disk('documents')->delete($artifact->path);

        $this->artisan('esign:artifacts:verify')
            ->expectsOutputToContain('not present on its disk')
            ->assertFailed();

        $run = ArtifactVerificationRun::lastCompleted();

        $this->assertSame(1, $run->missing_objects);
        $this->assertSame('missing', $run->findings[0]['problem']);
    }

    /**
     * Bytes retention removed on purpose are not an integrity failure. Reporting them as one
     * would train an operator to ignore the probe.
     */
    public function test_artifacts_of_a_soft_deleted_envelope_are_skipped(): void
    {
        $scenario = RetentionScenario::finalized();

        foreach (Artifact::query()->get() as $artifact) {
            Storage::disk('documents')->delete($artifact->path);
        }

        $scenario->envelope->refresh()->delete();

        $this->artisan('esign:artifacts:verify')
            ->expectsOutputToContain('0 artifact(s) checked')
            ->assertSuccessful();
    }

    public function test_the_workspace_and_since_filters_narrow_the_run_and_are_recorded(): void
    {
        $scenario = RetentionScenario::finalized();
        $workspace = $scenario->signing->workspace->public_id;

        $this->artisan('esign:artifacts:verify', ['--workspace' => $workspace])->assertSuccessful();

        $run = ArtifactVerificationRun::lastCompleted();
        $this->assertSame($workspace, $run->workspace_public_id);
        $this->assertSame(3, $run->artifacts_checked);

        // Nothing was published tomorrow.
        $this->artisan('esign:artifacts:verify', ['--since' => CarbonImmutable::now()->addDay()->toDateString()])
            ->expectsOutputToContain('0 artifact(s) checked')
            ->assertSuccessful();

        $this->assertNotNull(ArtifactVerificationRun::lastCompleted()->published_since);
    }

    public function test_the_command_refuses_an_unknown_workspace_or_an_unreadable_since(): void
    {
        $this->artisan('esign:artifacts:verify', ['--workspace' => 'nope'])->assertExitCode(2);
        $this->artisan('esign:artifacts:verify', ['--since' => 'the day before whenever'])->assertExitCode(2);
    }

    // ---------------------------------------------------------------------------------
    // The readiness probe
    // ---------------------------------------------------------------------------------

    public function test_the_probe_warns_when_no_verification_has_ever_completed(): void
    {
        $result = (new ArtifactIntegrityProbe)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
        $this->assertStringContainsString('No artifact integrity verification', $result->message);
    }

    public function test_the_probe_is_ok_after_a_recent_passing_run(): void
    {
        RetentionScenario::finalized();
        app(ArtifactIntegrityVerifier::class)->verify();

        $result = (new ArtifactIntegrityProbe)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('no mismatches', $result->message);
    }

    public function test_the_probe_warns_when_the_last_passing_run_is_older_than_eight_days(): void
    {
        RetentionScenario::finalized();
        $run = app(ArtifactIntegrityVerifier::class)->verify();

        RetentionScenario::backdate('artifact_verification_runs', (int) $run->getKey(), [
            'started_at' => RetentionScenario::daysAgo(9),
            'finished_at' => RetentionScenario::daysAgo(9),
        ]);

        $result = (new ArtifactIntegrityProbe)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
        $this->assertStringContainsString('may not be running', $result->message);
    }

    public function test_the_probe_fails_when_the_last_run_found_a_mismatch(): void
    {
        RetentionScenario::finalized();

        $artifact = Artifact::query()->where('kind', ArtifactKind::EvidenceJson->value)->sole();
        Storage::disk('documents')->put($artifact->path, 'not the evidence document');

        app(ArtifactIntegrityVerifier::class)->verify();

        $result = (new ArtifactIntegrityProbe)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
        $this->assertStringContainsString('1 digest mismatch', $result->message);
    }

    public function test_the_probe_message_never_names_an_envelope_or_a_storage_key(): void
    {
        $scenario = RetentionScenario::finalized();

        $artifact = Artifact::query()->where('kind', ArtifactKind::EvidenceJson->value)->sole();
        Storage::disk('documents')->put($artifact->path, 'tampered');

        app(ArtifactIntegrityVerifier::class)->verify();

        $message = (new ArtifactIntegrityProbe)->check()->message;

        $this->assertStringNotContainsString($artifact->path, $message);
        $this->assertStringNotContainsString($scenario->envelope->public_id, $message);
        $this->assertStringNotContainsString($scenario->envelope->title, $message);
    }

    public function test_the_probe_is_registered_on_the_readiness_endpoint(): void
    {
        config(['esign.health_token' => 'synthetic-health-token']);

        $response = $this->withHeaders(['Authorization' => 'Bearer synthetic-health-token'])
            ->getJson('/health/ready');

        $response->assertJsonStructure(['probes' => ['artifact_integrity' => ['status', 'message']]]);
    }
}
