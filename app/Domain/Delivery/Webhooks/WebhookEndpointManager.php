<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use App\Domain\Delivery\Outbound\Exceptions\DestinationRefusedException;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Administrative changes to an endpoint, each of them audited.
 *
 * Every method here writes an audit event, because these are the operations
 * that decide where a workspace's events go and who can verify them. Secrets
 * never appear in an audit payload — only the fact that a rotation happened and
 * when the old secret stops verifying.
 */
final class WebhookEndpointManager
{
    public function __construct(
        private readonly WebhookTransport $transport,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>|null  $eventFilter  Null for every event.
     * @return array{WebhookEndpoint, string} The endpoint and its plaintext secret, shown once.
     *
     * @throws DestinationRefusedException
     * @throws Exceptions\UnknownEventNameException
     */
    public function create(
        Workspace $workspace,
        string $url,
        ?string $description = null,
        ?array $eventFilter = null,
        ?AuditActor $actor = null,
    ): array {
        // Refused at creation rather than at delivery time, so an operator finds
        // out from the command they just ran instead of from a queue log.
        $this->transport->validate($url);

        foreach ($eventFilter ?? [] as $eventName) {
            WebhookEventName::assertKnown($eventName);
        }

        $secret = WebhookSecret::generate();

        $endpoint = new WebhookEndpoint([
            'workspace_id' => $workspace->getKey(),
            'url' => $url,
            'description' => $description,
            'event_filter' => $eventFilter,
            'secret_current' => $secret,
        ]);
        $endpoint->save();

        $this->audit->record(
            $actor ?? AuditActor::system('webhook-administration'),
            'webhook.endpoint.created',
            $endpoint,
            [
                'endpoint' => $endpoint->public_id,
                'workspace' => $workspace->public_id,
                'url' => $url,
                'event_filter' => $eventFilter,
            ],
        );

        return [$endpoint, $secret];
    }

    /**
     * Issue a new secret and keep the old one verifying for a grace period.
     *
     * Both secrets sign every attempt during the overlap, so a receiver can be
     * reconfigured at any point inside the window without dropping an event. A
     * second rotation inside the window deliberately discards the
     * secret-before-last: only ever two secrets are live.
     *
     * @return string The new plaintext secret, shown once.
     */
    public function rotateSecret(
        WebhookEndpoint $endpoint,
        ?int $graceHours = null,
        ?AuditActor $actor = null,
    ): string {
        $graceHours = max(0, $graceHours ?? (int) config('esign.delivery.webhooks.secret_rotation_grace_hours', 168));
        $expiresAt = CarbonImmutable::now()->addHours($graceHours);

        $endpoint->secret_previous = $endpoint->secret_current;
        $endpoint->secret_previous_expires_at = $expiresAt;
        $endpoint->secret_current = $secret = WebhookSecret::generate();
        $endpoint->save();

        $this->audit->record(
            $actor ?? AuditActor::system('webhook-administration'),
            'webhook.endpoint.secret_rotated',
            $endpoint,
            [
                'endpoint' => $endpoint->public_id,
                'previous_secret_expires_at' => $expiresAt->toIso8601String(),
            ],
        );

        return $secret;
    }

    public function disable(WebhookEndpoint $endpoint, string $reason, ?AuditActor $actor = null): void
    {
        $endpoint->disabled_at = CarbonImmutable::now();
        $endpoint->disabled_reason = $reason;
        $endpoint->save();

        $this->audit->record(
            $actor ?? AuditActor::system('webhook-administration'),
            'webhook.endpoint.disabled',
            $endpoint,
            ['endpoint' => $endpoint->public_id, 'reason' => $reason],
        );
    }

    /**
     * Re-enabling clears the failure streak: the next auto-disable should count
     * failures since the operator said the receiver was fixed, not since the
     * beginning of time.
     */
    public function enable(WebhookEndpoint $endpoint, ?AuditActor $actor = null): void
    {
        $endpoint->disabled_at = null;
        $endpoint->disabled_reason = null;
        $endpoint->consecutive_failures = 0;
        $endpoint->save();

        $this->audit->record(
            $actor ?? AuditActor::system('webhook-administration'),
            'webhook.endpoint.enabled',
            $endpoint,
            ['endpoint' => $endpoint->public_id],
        );
    }
}
