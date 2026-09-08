<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\EnvironmentProbe;
use Tests\TestCase;

class EnvironmentProbeTest extends TestCase
{
    public function test_is_ok_when_app_key_is_set_and_env_exists(): void
    {
        // The test suite's own .env exists at the repository root and APP_KEY is always set
        // (phpunit.xml sets it explicitly), so this is a real assertion, not a tautology.
        $result = $this->app->make(EnvironmentProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_when_app_key_is_not_set(): void
    {
        config()->set('app.key', '');

        $result = $this->app->make(EnvironmentProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
