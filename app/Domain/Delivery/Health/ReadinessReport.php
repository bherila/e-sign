<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

/**
 * Aggregates every probe result into one overall status and HTTP code.
 *
 * Overall status is "ok" only if every probe is ok, "degraded" if the worst
 * probe is a warn, and "fail" if any probe fails. "ok" and "degraded" both
 * map to HTTP 200 so a load balancer keeps routing traffic while an operator
 * investigates; "fail" maps to 503.
 */
final class ReadinessReport
{
    /**
     * @param  ProbeResult[]  $probes
     */
    public function __construct(public readonly array $probes) {}

    public function overallStatus(): string
    {
        $worst = HealthStatus::worst(array_map(
            static fn (ProbeResult $probe): HealthStatus => $probe->status,
            $this->probes,
        ));

        return match ($worst) {
            HealthStatus::Ok => 'ok',
            HealthStatus::Warn => 'degraded',
            HealthStatus::Fail => 'fail',
        };
    }

    public function httpStatus(): int
    {
        return $this->overallStatus() === 'fail' ? 503 : 200;
    }

    /**
     * @return array{status: string, probes: array<string, array{status: string, message: string}>}
     */
    public function toArray(): array
    {
        $probes = [];

        foreach ($this->probes as $probe) {
            $probes[$probe->name] = $probe->toArray();
        }

        return [
            'status' => $this->overallStatus(),
            'probes' => $probes,
        ];
    }
}
