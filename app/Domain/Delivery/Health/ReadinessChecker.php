<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

/**
 * Runs every registered readiness probe and assembles the report. The probe
 * list is bound in App\Providers\HealthServiceProvider.
 */
final class ReadinessChecker
{
    /**
     * @param  HealthProbe[]  $probes
     */
    public function __construct(private readonly array $probes) {}

    public function run(): ReadinessReport
    {
        return new ReadinessReport(array_map(
            static fn (HealthProbe $probe): ProbeResult => $probe->check(),
            $this->probes,
        ));
    }
}
