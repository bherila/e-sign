<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\PhpRuntimeProbe;
use Tests\TestCase;

class PhpRuntimeProbeTest extends TestCase
{
    public function test_is_ok_on_the_php_version_and_extensions_this_test_suite_actually_runs_under(): void
    {
        // CI and every supported local dev machine run PHP 8.4+ with every required extension
        // loaded (composer.json requires ^8.4 and the app itself needs several of them), so this
        // is a real assertion about the environment, not a tautology.
        $result = $this->app->make(PhpRuntimeProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }
}
