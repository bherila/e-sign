<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\WebPhpVersionProbe;
use Tests\TestCase;

class WebPhpVersionProbeTest extends TestCase
{
    public function test_warns_when_not_configured(): void
    {
        config()->set('esign.cpanel.web_php_version', '');

        $result = $this->app->make(WebPhpVersionProbe::class)->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
    }

    public function test_is_ok_when_the_configured_web_version_matches_this_cli(): void
    {
        config()->set('esign.cpanel.web_php_version', PHP_VERSION);

        $result = $this->app->make(WebPhpVersionProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_when_the_configured_web_version_differs_from_this_cli(): void
    {
        config()->set('esign.cpanel.web_php_version', '1.2.3');

        $result = $this->app->make(WebPhpVersionProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
