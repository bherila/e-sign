<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Assurance\ConfiguredSealAssurancePolicyCheck;
use App\Domain\Signing\Assurance\SealMaterialAssurancePolicyCheck;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use Tests\TestCase;

/**
 * The default answer to "can this deployment seal at the level the envelope asked for?".
 *
 * Shallow by design: it reads paths, never key contents, and never contacts a timestamp
 * authority. It is the first half of the bound check, and the half that produces the precise
 * message an operator wants for an unfinished install.
 *
 * The second half is {@see SealMaterialAssurancePolicyCheck}, which is what the container
 * binds: it runs this check first and then the sealer's own preflight, so an expired
 * certificate or a key that does not match its certificate is refused before signers are
 * invited too (docs/HANDOFF.md section 9). Neither half ever contacts a timestamp authority.
 */
class ConfiguredSealAssurancePolicyCheckTest extends TestCase
{
    public function test_the_configuration_check_is_the_first_half_of_the_default_binding(): void
    {
        $this->assertInstanceOf(SealMaterialAssurancePolicyCheck::class, app(AssurancePolicyCheck::class));
    }

    public function test_an_unconfigured_deployment_cannot_send(): void
    {
        config(['esign.seal.certificate_path' => '', 'esign.seal.private_key_path' => '']);

        $this->assertSame(
            'The service seal certificate and private key are not configured.',
            $this->check()->unavailableReason(AssuranceLevel::PadesBB),
        );
    }

    public function test_configured_and_readable_material_satisfies_b_b(): void
    {
        $this->configureSealMaterial();

        $this->assertNull($this->check()->unavailableReason(AssuranceLevel::PadesBB));
    }

    public function test_material_that_is_not_readable_is_reported(): void
    {
        $this->configureSealMaterial();
        config(['esign.seal.certificate_path' => base_path('tests/Fixtures/crypto/does-not-exist.crt')]);

        $this->assertSame(
            'The service seal certificate is not readable.',
            $this->check()->unavailableReason(AssuranceLevel::PadesBB),
        );
    }

    /** No downgrade: B-T without a TSA is an error, not B-B. */
    public function test_b_t_additionally_requires_a_timestamp_authority(): void
    {
        $this->configureSealMaterial();
        config(['esign.tsa.url' => '']);

        $this->assertNull($this->check()->unavailableReason(AssuranceLevel::PadesBB));
        $this->assertSame(
            'Assurance level pades-b-t requires a timestamp authority, and none is configured.',
            $this->check()->unavailableReason(AssuranceLevel::PadesBT),
        );

        config(['esign.tsa.url' => 'https://tsa.example.test/tsr']);

        $this->assertNull($this->check()->unavailableReason(AssuranceLevel::PadesBT));
    }

    private function configureSealMaterial(): void
    {
        config([
            'esign.seal.certificate_path' => base_path('tests/Fixtures/crypto/seal.test.crt'),
            'esign.seal.private_key_path' => base_path('tests/Fixtures/crypto/seal.test.pkey'),
        ]);
    }

    private function check(): ConfiguredSealAssurancePolicyCheck
    {
        return new ConfiguredSealAssurancePolicyCheck(app('config'));
    }
}
