<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\Doctor\QueueConnectionProbe;
use Tests\TestCase;

class QueueConnectionProbeTest extends TestCase
{
    public function test_is_ok_on_the_database_connection(): void
    {
        config()->set('queue.default', 'database');

        $result = $this->app->make(QueueConnectionProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
    }

    public function test_fails_on_any_other_connection(): void
    {
        config()->set('queue.default', 'sync');

        $result = $this->app->make(QueueConnectionProbe::class)->check();

        $this->assertSame(HealthStatus::Fail, $result->status);
    }
}
