<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\WebhookBacklog;

/**
 * Queue age and counts for the webhook outbox, for an operator at a terminal.
 * The same numbers the readiness probe reports.
 */
final class BacklogCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:backlog';

    protected $description = 'Show webhook delivery backlog age and counts.';

    public function handle(WebhookBacklog $backlog): int
    {
        $snapshot = $backlog->snapshot();

        $this->table(['Measure', 'Value'], [
            ['Overdue attempts', (string) $snapshot->overdue],
            ['Oldest overdue attempt', $snapshot->oldestOverdueSeconds === null
                ? 'none'
                : $snapshot->oldestOverdueSeconds.'s'],
            ['Retries scheduled for later', (string) $snapshot->scheduled],
            ['Delivered in the last 24h', (string) $snapshot->succeededLast24h],
            ['Failed in the last 24h', (string) $snapshot->failedLast24h],
            ['Exhausted in the last 24h', (string) $snapshot->exhaustedLast24h],
            ['Disabled endpoints', (string) $snapshot->disabledEndpoints],
        ]);

        if ($snapshot->disabledEndpoints > 0) {
            $this->components->warn(
                $snapshot->disabledEndpoints.' endpoint(s) are disabled. '.
                'Run esign:webhook:endpoint:list to see why.'
            );
        }

        return self::SUCCESS;
    }
}
