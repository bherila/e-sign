<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Confirms the Stage 3 sealing certificate and private key are configured
 * and readable, and reports certificate expiry. The private key is never
 * read past an is_readable() check; its contents never enter this process.
 */
final class SigningMaterialProbe implements HealthProbe
{
    public function name(): string
    {
        return 'signing_material';
    }

    public function check(): ProbeResult
    {
        $certificatePath = config('esign.seal.certificate_path');
        $privateKeyPath = config('esign.seal.private_key_path');

        if (! is_string($certificatePath) || $certificatePath === '' || ! is_string($privateKeyPath) || $privateKeyPath === '') {
            if (app()->environment('production')) {
                return ProbeResult::fail($this->name(), 'Signing certificate/key are not configured.');
            }

            return ProbeResult::warn($this->name(), 'Signing certificate/key are not configured.');
        }

        if (! is_readable($privateKeyPath)) {
            return ProbeResult::fail($this->name(), 'Signing private key is not readable.');
        }

        if (! is_readable($certificatePath)) {
            return ProbeResult::fail($this->name(), 'Signing certificate is not readable.');
        }

        try {
            $contents = file_get_contents($certificatePath);
        } catch (Throwable) {
            $contents = false;
        }

        if ($contents === false || $contents === '') {
            return ProbeResult::fail($this->name(), 'Signing certificate could not be read.');
        }

        $parsed = @openssl_x509_parse($contents);

        if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
            return ProbeResult::fail($this->name(), 'Signing certificate could not be parsed.');
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
        $daysRemaining = CarbonImmutable::now()->diffInDays($expiresAt, false);
        $warnDays = (int) config('esign.health.cert_warn_days', 30);

        if ($daysRemaining <= 0) {
            return ProbeResult::fail($this->name(), 'Signing certificate has expired.');
        }

        if ($daysRemaining < $warnDays) {
            return ProbeResult::warn($this->name(), "Signing certificate expires in {$daysRemaining} day(s).");
        }

        return ProbeResult::ok($this->name(), "Signing certificate expires in {$daysRemaining} day(s).");
    }
}
