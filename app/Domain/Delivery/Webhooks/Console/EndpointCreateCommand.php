<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Webhooks\Exceptions\UnknownEventNameException;
use App\Domain\Delivery\Webhooks\WebhookEndpointManager;

/**
 * Register a webhook destination for a workspace.
 *
 * The URL passes the outbound destination policy here, not at delivery time, so
 * a destination that can never be reached is refused while an operator is
 * looking at the terminal.
 */
final class EndpointCreateCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:endpoint:create
        {workspace : Workspace slug or public id}
        {url : HTTPS destination for deliveries}
        {--description= : What this endpoint is, for operators reading the list}
        {--event=* : Event name to deliver; repeatable. Omit for every event.}';

    protected $description = 'Register a webhook endpoint and print its signing secret once.';

    public function handle(WebhookEndpointManager $manager): int
    {
        $workspace = $this->findWorkspace((string) $this->argument('workspace'));

        if ($workspace === null) {
            return $this->refuse(
                'No workspace matches "'.$this->argument('workspace').'".',
                'Pass the workspace slug or its public id.',
            );
        }

        /** @var list<string> $events */
        $events = array_values(array_filter((array) $this->option('event')));

        try {
            [$endpoint, $secret] = $manager->create(
                workspace: $workspace,
                url: (string) $this->argument('url'),
                description: $this->option('description') === null ? null : (string) $this->option('description'),
                eventFilter: $events === [] ? null : $events,
                actor: $this->actor(),
            );
        } catch (DestinationRefusedException $refusal) {
            return $this->refuse(
                $refusal->getMessage(),
                'Fix the URL, or add an administrator allowlist entry to ESIGN_DELIVERY_ALLOWLIST '.
                'if this is a named internal consumer.',
            );
        } catch (UnknownEventNameException $unknown) {
            return $this->refuse(
                $unknown->getMessage(),
                'See docs/compatibility/firma-capability-matrix.md for the event names in profile.',
            );
        }

        $this->components->info('Endpoint '.$endpoint->public_id.' created.');
        $this->line('  Signing secret: '.$secret);
        $this->line('  This is the only time the secret is shown. Store it in the receiver now.');

        return self::SUCCESS;
    }
}
