<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizationScenario;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * The key-management half of issue #29: which material is in force, recorded where.
 *
 * A rotation is a new key id and new files, never an edit of the old ones, and the thing that
 * makes that survivable is that every artifact records the key id and certificate digest that
 * sealed it. These tests pin both halves — the recording, and the status surface an operator
 * uses to know what they are about to rotate away from.
 */
class SealKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_status_command_reports_the_material_without_the_private_key(): void
    {
        FinalizationScenario::configureSeal();

        $this->artisan('esign:seal:status')
            ->expectsOutputToContain(SealingFixtures::KEY_ID)
            ->expectsOutputToContain('NOT FOR USE')
            ->expectsOutputToContain('pades-b-b')
            ->assertSuccessful();
    }

    public function test_the_status_command_lists_retired_keys_and_flags_a_missing_certificate(): void
    {
        FinalizationScenario::configureSeal();

        config(['esign.seal.retired_keys' => [[
            'key_id' => 'fixture-seal-2025-a',
            'certificate_path' => SealingFixtures::cryptoPath('seal-b.test.crt'),
            'chain_path' => '',
        ]]]);

        $this->artisan('esign:seal:status')
            ->expectsOutputToContain('fixture-seal-2025-a')
            ->expectsOutputToContain('Retired seal keys')
            ->assertSuccessful();

        // A retired certificate that cannot be loaded is published evidence this deployment can
        // no longer attribute, so it fails the exit status a cron check reads.
        config(['esign.seal.retired_keys' => [[
            'key_id' => 'fixture-seal-2025-a',
            'certificate_path' => SealingFixtures::cryptoPath('gone.test.crt'),
            'chain_path' => '',
        ]]]);

        $this->artisan('esign:seal:status')
            ->expectsOutputToContain('UNRESOLVABLE')
            ->assertFailed();
    }

    public function test_the_status_command_never_prints_key_material(): void
    {
        FinalizationScenario::configureSeal();

        $this->artisan('esign:seal:status')->run();

        $output = $this->artisan('esign:seal:status')->run();

        $this->assertSame(0, $output);

        // Belt and braces: the port that feeds the command has no way to return a key.
        $methods = get_class_methods(SealIdentity::class);
        foreach ($methods as $method) {
            $this->assertStringNotContainsStringIgnoringCase('privatekey', $method);
        }
    }

    public function test_an_unusable_deployment_reports_failure(): void
    {
        config([
            'esign.seal.key_id' => '',
            'esign.seal.certificate_path' => '',
            'esign.seal.private_key_path' => '',
        ]);

        $this->artisan('esign:seal:status')
            ->expectsOutputToContain('not usable')
            ->assertFailed();
    }

    public function test_it_reports_that_b_t_is_unreachable_without_a_timestamp_authority(): void
    {
        FinalizationScenario::configureSeal();

        $this->artisan('esign:seal:status')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();

        config(['esign.tsa.url' => 'https://tsa.example.test/tsr']);

        $this->artisan('esign:seal:status')
            ->expectsOutputToContain('pades-b-t')
            ->assertSuccessful();
    }

    public function test_a_certificate_inside_the_warning_window_fails_the_check(): void
    {
        FinalizationScenario::configureSeal();

        // The fixture certificate is valid for years; asking for a window wider than its
        // remaining life is the same question a near-expiry certificate would pose.
        $this->artisan('esign:seal:status', ['--warn-days' => 100_000])
            ->expectsOutputToContain('expires in')
            ->assertFailed();
    }

    public function test_every_artifact_records_the_key_that_sealed_it(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $identity = app(SealIdentity::class);

        foreach (Artifact::query()->get() as $artifact) {
            $this->assertSame($identity->keyId(), $artifact->seal_key_id);
            $this->assertSame($identity->certificateFingerprint(), $artifact->seal_certificate_sha256);
        }

        // Only the sealed artifact claims a level; the others carry no seal of their own.
        $executed = Artifact::query()->where('kind', ArtifactKind::ExecutedPdf->value)->firstOrFail();
        $this->assertNotNull($executed->assurance_level_reached);

        $report = Artifact::query()->where('kind', ArtifactKind::CompletionReport->value)->firstOrFail();
        $this->assertNull($report->assurance_level_reached);
        $this->assertNull($report->validation_report);
    }

    public function test_the_signer_certificate_in_the_artifact_matches_the_recorded_fingerprint(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $executed = Artifact::query()->where('kind', ArtifactKind::ExecutedPdf->value)->firstOrFail();

        // The evidence record cannot attribute an artifact to material that did not seal it:
        // the certificate the CMS verified against is the one the row names.
        $this->assertSame(
            $executed->seal_certificate_sha256,
            $executed->validation_report['signer_fingerprint'],
        );
    }

    public function test_an_artifact_is_immutable_and_cannot_be_deleted(): void
    {
        $scenario = FinalizationScenario::signed();
        $scenario->finalizer()->finalize($scenario->envelope);

        $artifact = Artifact::query()->firstOrFail();

        try {
            $artifact->update(['sha256' => str_repeat('0', 64)]);
            $this->fail('An artifact was updated.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $artifact->delete();
            $this->fail('An artifact was deleted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }
    }
}
