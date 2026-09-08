<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;

/**
 * Show the endpoints and their health, including why a disabled one is
 * disabled. Never shows a secret.
 */
final class EndpointListCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:endpoint:list
        {--workspace= : Limit to one workspace, by slug or public id}';

    protected $description = 'List webhook endpoints with their state, filters, and failure counts.';

    public function handle(): int
    {
        $query = WebhookEndpoint::query()->with('workspace')->orderBy('id');

        $identifier = $this->option('workspace');
        if ($identifier !== null) {
            $workspace = $this->findWorkspace((string) $identifier);

            if ($workspace === null) {
                return $this->refuse(
                    'No workspace matches "'.$identifier.'".',
                    'Pass the workspace slug or its public id.',
                );
            }

            $query->where('workspace_id', $workspace->getKey());
        }

        $endpoints = $query->get();

        if ($endpoints->isEmpty()) {
            $this->components->info('No webhook endpoints are registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Endpoint', 'Workspace', 'URL', 'Events', 'State', 'Failures'],
            $endpoints->map(fn (WebhookEndpoint $endpoint): array => [
                $endpoint->public_id,
                $endpoint->workspace->slug,
                $endpoint->url,
                $endpoint->event_filter === null ? 'all' : implode(', ', $endpoint->event_filter),
                $endpoint->isEnabled() ? 'enabled' : 'disabled: '.($endpoint->disabled_reason ?? 'no reason recorded'),
                (string) $endpoint->consecutive_failures,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
