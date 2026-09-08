<?php

declare(strict_types=1);

namespace Tests\Unit\Evidence;

use App\Domain\Evidence\Sealing\Exceptions\SealMaterialInvalidException;
use App\Domain\Evidence\Sealing\Exceptions\SealMaterialUnavailableException;
use App\Domain\Evidence\Sealing\SealMaterial;
use PHPUnit\Framework\TestCase;
use Tests\Support\SealingFixtures;

/**
 * The gate that refuses absent, unusable, expired, or mismatched seal material.
 *
 * Each case is a release-gate requirement from docs/HANDOFF.md section 9: the
 * pipeline must reject bad material rather than discover it while finalizing an
 * envelope that signers have already signed.
 */
final class SealMaterialTest extends TestCase
{
    public function test_it_accepts_the_synthetic_fixture_material(): void
    {
        $material = SealingFixtures::material();

        $this->assertSame(SealingFixtures::KEY_ID, $material->keyId);
        $this->assertSame('sha256', $material->digestAlgorithm);
        $this->assertStringContainsString('NOT FOR USE', $material->subject);
        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $material->certificatePem());
        $this->assertGreaterThan(time(), $material->notAfter->getTimestamp());
    }

    public function test_it_decrypts_a_passphrase_protected_private_key(): void
    {
        $material = SealingFixtures::material(
            privateKey: 'seal-encrypted.test.pkey',
            passphrase: SealingFixtures::TEST_PASSPHRASE,
        );

        $this->assertSame(SealingFixtures::KEY_ID, $material->keyId);
    }

    public function test_it_rejects_a_wrong_passphrase(): void
    {
        $this->expectException(SealMaterialInvalidException::class);
        $this->expectExceptionMessageMatches('/passphrase/');

        SealingFixtures::material(
            privateKey: 'seal-encrypted.test.pkey',
            passphrase: 'not-the-passphrase',
        );
    }

    public function test_it_rejects_a_private_key_that_does_not_match_the_certificate(): void
    {
        $this->expectException(SealMaterialInvalidException::class);
        $this->expectExceptionMessageMatches('/does not match/');

        SealingFixtures::material(privateKey: 'wrong.test.pkey');
    }

    public function test_it_rejects_an_expired_certificate(): void
    {
        $this->expectException(SealMaterialInvalidException::class);
        $this->expectExceptionMessageMatches('/expired/');

        SealingFixtures::material(
            certificate: 'seal-expired.test.crt',
            privateKey: 'seal-expired.test.pkey',
        );
    }

    public function test_it_rejects_an_unreadable_certificate(): void
    {
        $this->expectException(SealMaterialInvalidException::class);

        SealMaterial::fromPem(
            keyId: 'x',
            certificatePem: 'not a certificate',
            privateKeyPem: SealingFixtures::pem('seal.test.pkey'),
        );
    }

    public function test_it_rejects_an_unsupported_digest_algorithm(): void
    {
        $this->expectException(SealMaterialInvalidException::class);
        $this->expectExceptionMessageMatches('/digest algorithm/');

        SealMaterial::fromConfig([
            'key_id' => 'x',
            'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
            'private_key_path' => SealingFixtures::cryptoPath('seal.test.pkey'),
            'digest_algorithm' => 'sha1',
        ]);
    }

    public function test_it_rejects_an_unconfigured_deployment(): void
    {
        $this->expectException(SealMaterialUnavailableException::class);
        $this->expectExceptionMessageMatches('/ESIGN_SEAL_CERTIFICATE_PATH/');

        SealMaterial::fromConfig([]);
    }

    public function test_it_rejects_a_configured_path_that_does_not_exist(): void
    {
        $this->expectException(SealMaterialUnavailableException::class);
        $this->expectExceptionMessageMatches('/missing or unreadable/');

        SealMaterial::fromConfig([
            'key_id' => 'x',
            'certificate_path' => SealingFixtures::cryptoPath('there-is-no-such.test.crt'),
            'private_key_path' => SealingFixtures::cryptoPath('seal.test.pkey'),
            'digest_algorithm' => 'sha256',
        ]);
    }

    public function test_it_rejects_material_without_a_key_id(): void
    {
        $this->expectException(SealMaterialUnavailableException::class);
        $this->expectExceptionMessageMatches('/ESIGN_SEAL_KEY_ID/');

        SealMaterial::fromConfig([
            'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
            'private_key_path' => SealingFixtures::cryptoPath('seal.test.pkey'),
            'digest_algorithm' => 'sha256',
        ]);
    }

    public function test_it_keeps_the_private_key_out_of_debug_output(): void
    {
        $material = SealingFixtures::material();
        $dumped = print_r($material, true);

        $this->assertStringNotContainsString('PRIVATE KEY', $dumped);
        $this->assertStringContainsString('***', $dumped);
    }
}
