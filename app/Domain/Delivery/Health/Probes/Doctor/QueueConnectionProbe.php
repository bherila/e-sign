<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes\Doctor;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;

/**
 * The cPanel profile has no Redis and no persistent daemon (docs/HANDOFF.md section 13):
 * `esign:queue:work-bounded` and its database lease only make sense on the `database` queue
 * connection. `sync` would run jobs inline on the request that dispatched them (defeating the
 * point of queueing at all, and turning a slow mail/sealing job into web-request latency);
 * anything else needs infrastructure this profile does not claim to run.
 */
final class QueueConnectionProbe implements HealthProbe
{
    public function name(): string
    {
        return 'queue_connection';
    }

    public function check(): ProbeResult
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'database') {
            return ProbeResult::fail(
                $this->name(),
                "QUEUE_CONNECTION is '{$connection}'; the cPanel profile requires 'database'.",
            );
        }

        return ProbeResult::ok($this->name(), 'Queue connection is database.');
    }
}
