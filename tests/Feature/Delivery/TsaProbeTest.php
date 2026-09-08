<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\TsaProbe;
use Tests\TestCase;

class TsaProbeTest extends TestCase
{
    public function test_is_ok_when_unconfigured(): void
    {
        config()->set('esign.tsa_url', null);

        $result = $this->app->make(TsaProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_is_ok_with_a_well_formed_https_url(): void
    {
        config()->set('esign.tsa_url', 'https://tsa.example.test/timestamp');

        $result = $this->app->make(TsaProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_with_a_non_http_scheme(): void
    {
        config()->set('esign.tsa_url', 'ftp://tsa.example.test/timestamp');

        $result = $this->app->make(TsaProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_never_makes_a_network_call(): void
    {
        // A URL that would hang or error on a real connection attempt; the probe must
        // return instantly and without a network exception because it never dials out.
        config()->set('esign.tsa_url', 'https://10.255.255.1:1/unreachable');

        $result = $this->app->make(TsaProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }
}
