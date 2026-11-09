<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Evidence\Sealing\Readiness\AttestSealReadiness;
use App\Domain\Evidence\Sealing\Readiness\SealReadinessAttestation;
use App\Domain\Evidence\Sealing\Readiness\SealReadinessAttester;
use App\Domain\Signing\Assurance\AttestedSealAssurancePolicyCheck;
use App\Domain\Signing\Assurance\SealMaterialAssurancePolicyCheck;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizationScenario;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * Seal readiness attested by the worker, for a web role with no key (issue #37).
 *
 * In the Docker layout the private key reaches the worker alone, so the web role's send gate
 * cannot open it. The worker records what it finds; the gate trusts that record only while it is
 * fresh, for the configured key, and for a certificate still valid. Anything else refuses.
 */
final class SealReadinessAttestationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FinalizationScenario::configureSeal();
        config(['esign.seal.readiness' => 'attested']);
    }

    public function test_the_worker_records_usable_material_and_the_gate_accepts_it(): void
    {
        app(SealReadinessAttester::class)->attest();

        $row = SealReadinessAttestation::query()->sole();
        $this->assertSame(SealingFixtures::KEY_ID, $row->key_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row->certificate_sha256);
        $this->assertNull($row->levels[AssuranceLevel::PadesBB->value]);
        // No timestamp authority is configured, so B-T is recorded as unavailable, with its reason.
        $this->assertNotNull($row->levels[AssuranceLevel::PadesBT->value]);

        $check = app(AssurancePolicyCheck::class);
        $this->assertInstanceOf(AttestedSealAssurancePolicyCheck::class, $check);
        $this->assertNull($check->unavailableReason(AssuranceLevel::PadesBB));
        $this->assertNotNull($check->unavailableReason(AssuranceLevel::PadesBT));
    }

    public function test_the_gate_refuses_with_no_attestation(): void
    {
        $this->assertStringContainsString(
            'has not yet checked',
            (string) app(AssurancePolicyCheck::class)->unavailableReason(AssuranceLevel::PadesBB),
        );
    }

    public function test_a_stale_attestation_stops_vouching(): void
    {
        app(SealReadinessAttester::class)->attest(CarbonImmutable::now()->subMinutes(16));

        $this->assertStringContainsString(
            'Check that the worker is running',
            (string) app(AssurancePolicyCheck::class)->unavailableReason(AssuranceLevel::PadesBB),
        );
    }

    public function test_an_attestation_for_another_key_does_not_count(): void
    {
        app(SealReadinessAttester::class)->attest();
        config(['esign.seal.key_id' => 'fixture-seal-2026-b']);

        $this->assertNotNull(app(AssurancePolicyCheck::class)->unavailableReason(AssuranceLevel::PadesBB));
    }

    public function test_a_certificate_that_has_expired_since_the_check_is_refused(): void
    {
        app(SealReadinessAttester::class)->attest();
        SealReadinessAttestation::query()->update(['certificate_not_after' => CarbonImmutable::now()->subMinute()]);

        $this->assertNotNull(app(AssurancePolicyCheck::class)->unavailableReason(AssuranceLevel::PadesBB));
    }

    public function test_unusable_material_is_recorded_as_the_reason_rather_than_thrown(): void
    {
        config(['esign.seal.certificate_path' => base_path('tests/Fixtures/crypto/seal-expired.test.crt'),
            'esign.seal.private_key_path' => base_path('tests/Fixtures/crypto/seal-expired.test.pkey')]);
        $this->app->forgetInstance(SealIdentity::class);

        $this->artisan('esign:seal:attest')->assertSuccessful();

        $row = SealReadinessAttestation::query()->sole();
        $this->assertNotNull($row->levels[AssuranceLevel::PadesBB->value]);
        $this->assertNotNull(app(AssurancePolicyCheck::class)->unavailableReason(AssuranceLevel::PadesBB));
    }

    public function test_one_row_per_key_is_refreshed_in_place(): void
    {
        app(SealReadinessAttester::class)->attest(CarbonImmutable::now()->subMinutes(10));
        app(SealReadinessAttester::class)->attest();

        $this->assertSame(1, SealReadinessAttestation::query()->count());
        $this->assertNull(app(AssurancePolicyCheck::class)->unavailableReason(AssuranceLevel::PadesBB));
    }

    public function test_the_queued_job_attests(): void
    {
        (new AttestSealReadiness)->handle(app(SealReadinessAttester::class));

        $this->assertSame(1, SealReadinessAttestation::query()->count());
    }

    public function test_local_mode_keeps_opening_the_material_itself(): void
    {
        config(['esign.seal.readiness' => 'local']);

        $this->assertInstanceOf(SealMaterialAssurancePolicyCheck::class, app(AssurancePolicyCheck::class));
    }
}
