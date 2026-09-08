<?php

declare(strict_types=1);

namespace Tests\Unit\Evidence;

use App\Domain\Evidence\Sealing\Exceptions\SealMaterialInvalidException;
use App\Domain\Evidence\Sealing\Exceptions\SealMaterialUnavailableException;
use App\Domain\Evidence\Sealing\SealMaterial;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
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

    /**
     * `__debugInfo()` covers print_r() and var_dump() and nothing else.
     *
     * Symfony's VarDumper — which is what dd(), dump(), and an exception page's
     * stack-frame locals use — merges `__debugInfo()` with the reflected
     * property set instead of replacing it, and var_export() ignores the method
     * outright. Before the key was wrapped, dd(), serialize(), and var_export()
     * each wrote the unencrypted PEM out. Each vector is pinned separately so a
     * future refactor that unwraps the key fails here rather than in a log.
     */
    #[DataProvider('leakVectors')]
    public function test_the_private_key_does_not_escape_through(string $_label, callable $render): void
    {
        $material = SealingFixtures::material();

        $this->assertStringNotContainsString('PRIVATE KEY', $render($material));
    }

    /**
     * @return array<string, array{string, callable(SealMaterial): string}>
     */
    public static function leakVectors(): array
    {
        return [
            'print_r' => ['print_r', static fn (SealMaterial $m): string => print_r($m, true)],
            'var_export' => ['var_export', static fn (SealMaterial $m): string => var_export($m, true)],
            'var_dump' => ['var_dump', static function (SealMaterial $m): string {
                ob_start();
                var_dump($m);

                return (string) ob_get_clean();
            }],
            'json_encode' => ['json_encode', static fn (SealMaterial $m): string => (string) json_encode($m)],
            'VarDumper (dd/dump)' => ['VarDumper', static function (SealMaterial $m): string {
                $handle = fopen('php://memory', 'r+');
                (new CliDumper)->dump((new VarCloner)->cloneVar($m), $handle);
                rewind($handle);

                return (string) stream_get_contents($handle);
            }],
        ];
    }

    public function test_it_refuses_to_serialize_at_all(): void
    {
        // Not a redaction: SensitiveParameterValue throws rather than emit the
        // key, so an accidental queue payload or cache write fails loudly
        // instead of persisting the material.
        $this->expectException(Exception::class);

        serialize(SealingFixtures::material());
    }

    public function test_it_records_the_certificate_fingerprint_a_validator_reports(): void
    {
        $material = SealingFixtures::material();
        $expected = hash('sha256', SealMaterial::pemToDer(SealingFixtures::pem('seal.test.crt')));

        $this->assertSame($expected, $material->certificateFingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $material->certificateFingerprint);
    }
}
