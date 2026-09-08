<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Sealing;

use App\Domain\Evidence\Contracts\SealIdentity;
use App\Domain\Evidence\Contracts\TimestampAuthority;
use Closure;
use DateTimeImmutable;

/**
 * {@see SealIdentity} over the same `config('esign.seal')` material the sealer uses.
 *
 * The material is resolved through a closure and memoized per instance, so an unconfigured
 * deployment fails when something asks about the seal rather than when the container boots,
 * and a status command does not re-read the key file once per question it answers.
 */
final class ConfiguredSealIdentity implements SealIdentity
{
    /** @var Closure(): SealMaterial */
    private readonly Closure $resolve;

    private ?SealMaterial $material = null;

    /**
     * @param  Closure(): SealMaterial  $material
     */
    public function __construct(Closure $material, private readonly TimestampAuthority $timestampAuthority)
    {
        $this->resolve = $material;
    }

    public function keyId(): string
    {
        return $this->material()->keyId;
    }

    public function certificateFingerprint(): string
    {
        return $this->material()->certificateFingerprint;
    }

    public function subject(): string
    {
        return $this->material()->subject;
    }

    public function notAfter(): DateTimeImmutable
    {
        return $this->material()->notAfter;
    }

    public function certificatePem(): string
    {
        return $this->material()->certificatePem();
    }

    public function chainPem(): string
    {
        return $this->material()->chainPem();
    }

    public function digestAlgorithm(): string
    {
        return $this->material()->digestAlgorithm;
    }

    public function hasTimestampAuthority(): bool
    {
        return $this->timestampAuthority->isConfigured();
    }

    private function material(): SealMaterial
    {
        return $this->material ??= ($this->resolve)();
    }
}
