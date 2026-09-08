<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\WebhookBacklogProbe;
use Tests\TestCase;

class WebhookBacklogProbeTest extends TestCase
{
    public function test_is_ok_because_no_outbox_exists_yet(): void
    {
        $result = $this->app->make(WebhookBacklogProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsStringIgnoringCase('no outbox yet', $result->message);
    }
}
