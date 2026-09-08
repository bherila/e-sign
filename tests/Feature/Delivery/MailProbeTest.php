<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\MailProbe;
use Tests\TestCase;

class MailProbeTest extends TestCase
{
    public function test_is_ok_with_the_log_mailer_outside_production(): void
    {
        config()->set('mail.default', 'log');
        $this->app['env'] = 'local';

        $result = $this->app->make(MailProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_with_the_log_mailer_in_production(): void
    {
        config()->set('mail.default', 'log');
        $this->app['env'] = 'production';

        $result = $this->app->make(MailProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_fails_with_the_array_mailer_in_production(): void
    {
        config()->set('mail.default', 'array');
        $this->app['env'] = 'production';

        $result = $this->app->make(MailProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }

    public function test_is_ok_with_a_delivering_mailer_in_production(): void
    {
        config()->set('mail.default', 'smtp');
        $this->app['env'] = 'production';

        $result = $this->app->make(MailProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }
}
