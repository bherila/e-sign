<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health;

interface HealthProbe
{
    /**
     * Stable machine-readable key for this probe, used as its key in the
     * readiness JSON body (e.g. "database", "queue").
     */
    public function name(): string;

    /**
     * Run the probe. Implementations must never throw for an expected
     * failure mode (e.g. missing config, unreachable resource); they should
     * catch it and return HealthStatus::Fail with a safe message instead.
     */
    public function check(): ProbeResult;
}
