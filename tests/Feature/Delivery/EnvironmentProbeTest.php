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
        // APP_KEY is always set (phpunit.xml sets it explicitly). A .env usually exists at the
        // repository root, but not on every CI path, so create an empty one for the duration of
        // the test when it is missing and remove it afterwards.
        $envPath = base_path('.env');
        $created = false;

        if (! is_file($envPath)) {
            file_put_contents($envPath, '');
            $created = true;
        }

        try {
            $result = $this->app->make(EnvironmentProbe::class)->check();

            $this->assertSame(HealthStatus::Ok, $result->status);
        } finally {
            if ($created) {
                @unlink($envPath);
            }
        }
    }

    public function test_fails_when_app_key_is_not_set(): void
    {
        config()->set('app.key', '');

        $result = $this->app->make(EnvironmentProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
