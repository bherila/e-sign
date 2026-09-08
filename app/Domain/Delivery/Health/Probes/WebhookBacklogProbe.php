<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\ProbeResult;
use App\Domain\Delivery\Webhooks\WebhookBacklog;
use Throwable;

/**
 * Reports the age of the oldest overdue webhook delivery, against configured
 * warn and fail thresholds, plus how many endpoints are currently disabled.
 *
 * A disabled endpoint warns rather than fails: delivery to it has stopped, an
 * operator needs to know, but the instance is not unready. The message names no
 * URL and no endpoint, because the readiness body is read by whoever can reach
 * the probe (docs/operations/health.md).
 */
final class WebhookBacklogProbe implements HealthProbe
{
    public function __construct(private readonly WebhookBacklog $backlog) {}

    public function name(): string
    {
        return 'webhook_backlog';
    }

    public function check(): ProbeResult
    {
        try {
            $snapshot = $this->backlog->snapshot();
        } catch (Throwable) {
            return ProbeResult::fail($this->name(), 'The webhook outbox tables are unavailable.');
        }

        $warnAt = (int) config('esign.delivery.webhooks.backlog_warn_seconds', 300);
        $failAt = (int) config('esign.delivery.webhooks.backlog_fail_seconds', 1800);

        $age = $snapshot->oldestOverdueSeconds;

        $ageStatus = match (true) {
            $age === null => HealthStatus::Ok,
            $age >= $failAt => HealthStatus::Fail,
            $age >= $warnAt => HealthStatus::Warn,
            default => HealthStatus::Ok,
        };

        $disabledStatus = $snapshot->disabledEndpoints > 0 ? HealthStatus::Warn : HealthStatus::Ok;

        $ageMessage = $age === null
            ? 'No overdue deliveries.'
            : "Oldest overdue delivery is {$age}s old.";

        $message = $ageMessage." {$snapshot->overdue} overdue, {$snapshot->scheduled} scheduled, ".
            "{$snapshot->disabledEndpoints} endpoint(s) disabled.";

        return new ProbeResult($this->name(), HealthStatus::worst([$ageStatus, $disabledStatus]), $message);
    }
}
