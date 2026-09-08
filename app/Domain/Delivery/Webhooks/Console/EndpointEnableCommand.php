<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\WebhookEndpointManager;

/**
 * Resume delivery to an endpoint and clear its failure streak.
 */
final class EndpointEnableCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:endpoint:enable {endpoint : Endpoint public id}';

    protected $description = 'Enable a webhook endpoint and reset its consecutive-failure count.';

    public function handle(WebhookEndpointManager $manager): int
    {
        $endpoint = $this->findEndpoint((string) $this->argument('endpoint'));

        if ($endpoint === null) {
            return $this->refuse(
                'No endpoint matches "'.$this->argument('endpoint').'".',
                'Run esign:webhook:endpoint:list to see the endpoints and their public ids.',
            );
        }

        $manager->enable($endpoint, $this->actor());

        $this->components->info('Endpoint '.$endpoint->public_id.' enabled.');

        return self::SUCCESS;
    }
}
