<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\SigningMaterialProbe;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SigningMaterialProbeTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_warns_when_unconfigured_outside_production(): void
    {
        config()->set('esign.seal.certificate_path', null);
        config()->set('esign.seal.private_key_path', null);
        $this->app['env'] = 'local';

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
    }

    public function test_fails_when_unconfigured_in_production(): void
    {
        config()->set('esign.seal.certificate_path', null);
        config()->set('esign.seal.private_key_path', null);
        $this->app['env'] = 'production';

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_fails_when_the_certificate_path_is_unreadable(): void
    {
        [, $keyPath] = $this->generateCertificateAndKey(30);

        config()->set('esign.seal.certificate_path', '/nonexistent/'.uniqid().'/cert.pem');
        config()->set('esign.seal.private_key_path', $keyPath);

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_fails_when_the_private_key_path_is_unreadable(): void
    {
        [$certPath] = $this->generateCertificateAndKey(30);

        config()->set('esign.seal.certificate_path', $certPath);
        config()->set('esign.seal.private_key_path', '/nonexistent/'.uniqid().'/key.pem');

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_is_ok_for_a_certificate_far_from_expiry(): void
    {
        [$certPath, $keyPath] = $this->generateCertificateAndKey(365);

        config()->set('esign.seal.certificate_path', $certPath);
        config()->set('esign.seal.private_key_path', $keyPath);

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_warns_when_the_certificate_expires_soon(): void
    {
        [$certPath, $keyPath] = $this->generateCertificateAndKey(10);

        config()->set('esign.seal.certificate_path', $certPath);
        config()->set('esign.seal.private_key_path', $keyPath);
        config()->set('esign.health.cert_warn_days', 30);

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
    }

    public function test_fails_for_an_expired_certificate(): void
    {
        [$certPath, $keyPath] = $this->generateCertificateAndKey(1);

        config()->set('esign.seal.certificate_path', $certPath);
        config()->set('esign.seal.private_key_path', $keyPath);

        // The certificate was issued for 1 day; travel forward so it has expired.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(5));

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_never_returns_the_private_key_contents_in_the_message(): void
    {
        [$certPath, $keyPath] = $this->generateCertificateAndKey(30);
        $keyContents = file_get_contents($keyPath);

        config()->set('esign.seal.certificate_path', $certPath);
        config()->set('esign.seal.private_key_path', $keyPath);

        $result = $this->app->make(SigningMaterialProbe::class)->check();

        $this->assertStringNotContainsString($keyContents, $result->message);
        $this->assertStringNotContainsString($keyPath, $result->message);
        $this->assertStringNotContainsString($certPath, $result->message);
    }

    /**
     * @return array{0: string, 1: string} [certificatePath, privateKeyPath]
     */
    private function generateCertificateAndKey(int $days): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($privateKey, 'Test environment cannot generate an RSA key.');

        $csr = openssl_csr_new(['commonName' => 'esign-health-test'], $privateKey);
        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, $days);
        $this->assertNotFalse($certificate);

        openssl_x509_export($certificate, $certPem);
        openssl_pkey_export($privateKey, $keyPem);

        $certPath = tempnam(sys_get_temp_dir(), 'esign-health-cert-');
        $keyPath = tempnam(sys_get_temp_dir(), 'esign-health-key-');

        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);

        $this->tempFiles[] = $certPath;
        $this->tempFiles[] = $keyPath;

        return [$certPath, $keyPath];
    }
}
