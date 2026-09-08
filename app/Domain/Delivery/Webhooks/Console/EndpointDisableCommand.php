<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\WebhookEndpointManager;

/**
 * Stop delivering to an endpoint, with a reason an operator can read later.
 */
final class EndpointDisableCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:endpoint:disable
        {endpoint : Endpoint public id}
        {--reason= : Why, recorded on the endpoint and in the audit trail}';

    protected $description = 'Disable a webhook endpoint. Events stop being queued for it.';

    public function handle(WebhookEndpointManager $manager): int
    {
        $endpoint = $this->findEndpoint((string) $this->argument('endpoint'));

        if ($endpoint === null) {
            return $this->refuse(
                'No endpoint matches "'.$this->argument('endpoint').'".',
                'Run esign:webhook:endpoint:list to see the endpoints and their public ids.',
            );
        }

        $reason = trim((string) ($this->option('reason') ?? ''));
        $manager->disable($endpoint, $reason === '' ? 'Disabled by an operator.' : $reason, $this->actor());

        $this->components->info('Endpoint '.$endpoint->public_id.' disabled.');
        $this->line('  Events recorded while it is disabled are not queued for it; replay them after enabling.');

        return self::SUCCESS;
    }
}
