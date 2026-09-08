<?php

declare(strict_types=1);

namespace App\Domain\Signing\Assurance;

use App\Domain\Delivery\Health\Probes\SigningMaterialProbe;
use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Contracts\AssurancePolicyCheck;
use Illuminate\Contracts\Config\Repository;

/**
 * The default check: the seal material named in `config('esign.seal')` is configured and
 * readable, and for B-T a timestamp authority is configured too.
 *
 * Deliberately shallow. It reads paths, not keys: the private key's contents never enter
 * this process, exactly as {@see SigningMaterialProbe}
 * treats them. Parsing the certificate, checking its expiry, and proving the TSA answers are
 * the health probe's job on a schedule and the sealer's job at seal time; doing them again
 * on every send would make inviting a signer depend on a network round trip.
 *
 * There is no environment escape hatch that declares a level available without the material
 * behind it. A deployment that has not configured a seal cannot send, which is the intended
 * outcome (AGENTS.md, "Fail closed").
 */
final readonly class ConfiguredSealAssurancePolicyCheck implements AssurancePolicyCheck
{
    public function __construct(private Repository $config) {}

    public function unavailableReason(AssuranceLevel $level): ?string
    {
        $certificate = $this->path('esign.seal.certificate_path');
        $privateKey = $this->path('esign.seal.private_key_path');

        if ($certificate === null || $privateKey === null) {
            return 'The service seal certificate and private key are not configured.';
        }

        if (! is_readable($certificate)) {
            return 'The service seal certificate is not readable.';
        }

        if (! is_readable($privateKey)) {
            return 'The service seal private key is not readable.';
        }

        if ($level->requiresTimestamp() && $this->path('esign.tsa.url') === null) {
            return 'Assurance level '.$level->value.' requires a timestamp authority, and none is configured.';
        }

        return null;
    }

    /** A configured, non-empty string setting, or null. */
    private function path(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
