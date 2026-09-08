<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks\Console;

use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Identity\Audit\AuditRecorder;

/**
 * Re-deliver a recorded event.
 *
 * Safe to run more than once: a replay is a new attempt with a fresh signature
 * timestamp under the same logical event id, so a receiver that deduplicates on
 * the event id — which the contract asks it to do — recognises what it already
 * processed. The event body is never rebuilt; the bytes recorded at the time
 * are the bytes sent.
 */
final class ReplayCommand extends WebhookCommand
{
    protected $signature = 'esign:webhook:replay
        {event : Outbox event public id}
        {--endpoint= : Replay to one endpoint only, by public id}';

    protected $description = 'Queue a fresh delivery attempt for an already recorded event.';

    public function handle(WebhookDispatcher $dispatcher, AuditRecorder $audit): int
    {
        $event = $this->findEvent((string) $this->argument('event'));

        if ($event === null) {
            return $this->refuse(
                'No outbox event matches "'.$this->argument('event').'".',
                'The event id is the `id` field of the webhook envelope and the X-Esign-Event-Id header.',
            );
        }

        $endpoint = null;
        $identifier = $this->option('endpoint');

        if ($identifier !== null) {
            $endpoint = $this->findEndpoint((string) $identifier);

            if ($endpoint === null) {
                return $this->refuse(
                    'No endpoint matches "'.$identifier.'".',
                    'Run esign:webhook:endpoint:list to see the endpoints and their public ids.',
                );
            }

            if ($endpoint->workspace_id !== $event->workspace_id) {
                return $this->refuse(
                    'That endpoint belongs to a different workspace than the event.',
                    'An event is only ever delivered to endpoints in its own workspace.',
                );
            }

            if (! $endpoint->isEnabled()) {
                return $this->refuse(
                    'Endpoint '.$endpoint->public_id.' is disabled: '.($endpoint->disabled_reason ?? 'no reason recorded'),
                    'Run esign:webhook:endpoint:enable '.$endpoint->public_id.' first.',
                );
            }
        }

        $deliveries = $dispatcher->replay($event, $endpoint);

        if ($deliveries === []) {
            $this->components->warn('No enabled endpoint wants '.$event->event_name.'; nothing was queued.');

            return self::SUCCESS;
        }

        $audit->record(
            $this->actor(),
            'webhook.event.replayed',
            $event,
            [
                'event' => $event->public_id,
                'event_name' => $event->event_name,
                'attempts' => array_map(
                    static fn (WebhookDelivery $delivery): string => $delivery->public_id,
                    $deliveries,
                ),
            ],
        );

        $this->components->info('Queued '.count($deliveries).' attempt(s) for event '.$event->public_id.'.');

        foreach ($deliveries as $delivery) {
            $this->line('  attempt '.$delivery->attempt.' → endpoint '.$delivery->endpoint->public_id.
                ' (delivery '.$delivery->public_id.')');
        }

        return self::SUCCESS;
    }
}
