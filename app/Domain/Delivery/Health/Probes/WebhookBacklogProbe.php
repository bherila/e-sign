<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * TODO(#34): replace this placeholder once the Delivery module's webhook
 * outbox table exists. Until then there is nothing to measure, so this
 * probe always reports ok.
 */
final class WebhookBacklogProbe implements HealthProbe
{
    public function name(): string
    {
        return 'webhook_backlog';
    }

    public function check(): ProbeResult
    {
        return ProbeResult::ok($this->name(), 'No outbox yet.');
    }
}
