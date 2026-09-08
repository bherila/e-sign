<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\WebhookEndpointManager;

/**
 * Issue a new signing secret, keeping the old one valid for a grace period.
 */
final class EndpointRotateSecretCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:endpoint:rotate-secret
        {endpoint : Endpoint public id}
        {--grace-hours= : How long the old secret keeps signing; defaults to the configured overlap}';

    protected $description = 'Rotate an endpoint signing secret with an overlap window.';

    public function handle(WebhookEndpointManager $manager): int
    {
        $endpoint = $this->findEndpoint((string) $this->argument('endpoint'));

        if ($endpoint === null) {
            return $this->refuse(
                'No endpoint matches "'.$this->argument('endpoint').'".',
                'Run esign:webhook:endpoint:list to see the endpoints and their public ids.',
            );
        }

        $graceHours = $this->option('grace-hours');
        $secret = $manager->rotateSecret(
            $endpoint,
            $graceHours === null ? null : (int) $graceHours,
            $this->actor(),
        );

        $this->components->info('Endpoint '.$endpoint->public_id.' rotated.');
        $this->line('  New signing secret: '.$secret);
        $this->line('  Shown once. Both secrets sign every delivery until '.
            ($endpoint->secret_previous_expires_at?->toIso8601String() ?? 'now').'.');

        return self::SUCCESS;
    }
}
