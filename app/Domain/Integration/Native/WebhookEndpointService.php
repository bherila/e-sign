<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\WebhookEndpointManager;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Models\Workspace;

/**
 * Webhook endpoint administration for an API caller.
 *
 * Every write goes through {@see WebhookEndpointManager}, which validates the destination
 * against the outbound policy and writes the audit event. Nothing about endpoints is
 * reimplemented here; this class supplies the two things the manager deliberately does not
 * know about — the workspace an API caller is confined to, and that the actor is a service
 * credential rather than an operator at a console.
 *
 * ## Deleting an endpoint
 *
 * `DELETE` **retires** an endpoint: it is disabled and receives nothing further, and the row
 * stays. That is not a softened delete for its own sake — `webhook_deliveries` references
 * the endpoint with `restrictOnDelete`, because a delivery record naming an endpoint that no
 * longer exists is not a delivery record. Erasing the row would mean erasing the history of
 * what was sent where, which is the opposite of what a webhook log is for. The response says
 * so: the endpoint comes back with `enabled: false` rather than a bare `204` that implies it
 * is gone.
 */
final readonly class WebhookEndpointService
{
    /** What `DELETE` records as the reason, so the log distinguishes it from an operator pause. */
    public const RETIRED_REASON = 'Retired through the native API.';

    public function __construct(private WebhookEndpointManager $manager) {}

    /**
     * @return Page<WebhookEndpoint>
     *
     * @throws ApiException
     */
    public function list(Workspace $workspace, ?string $cursor, int $limit): Page
    {
        return Page::keyset(
            WebhookEndpoint::query()->where('workspace_id', $workspace->getKey()),
            $cursor,
            $limit,
        );
    }

    /**
     * @throws ApiException
     */
    public function find(Workspace $workspace, string $publicId): WebhookEndpoint
    {
        $endpoint = WebhookEndpoint::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('public_id', $publicId)
            ->first();

        if (! $endpoint instanceof WebhookEndpoint) {
            throw ApiException::notFound('webhook endpoint');
        }

        return $endpoint;
    }

    /**
     * @param  list<string>|null  $eventFilter  Null for every event.
     * @return array{WebhookEndpoint, string} The endpoint and its secret, shown exactly once.
     */
    public function create(
        Workspace $workspace,
        string $url,
        ?string $description,
        ?array $eventFilter,
        ?ServiceCredential $credential,
    ): array {
        return $this->manager->create($workspace, $url, $description, $eventFilter, $this->actor($credential));
    }

    /**
     * @param  array{url?: string, description?: string|null, event_filter?: list<string>|null}  $changes
     */
    public function update(
        WebhookEndpoint $endpoint,
        array $changes,
        ?bool $enabled,
        ?ServiceCredential $credential,
    ): WebhookEndpoint {
        $actor = $this->actor($credential);

        $endpoint = $this->manager->update($endpoint, $changes, $actor);

        if ($enabled === true && ! $endpoint->isEnabled()) {
            $this->manager->enable($endpoint, $actor);
        }

        if ($enabled === false && $endpoint->isEnabled()) {
            $this->manager->disable($endpoint, 'Disabled through the native API.', $actor);
        }

        return $endpoint->refresh();
    }

    public function retire(WebhookEndpoint $endpoint, ?ServiceCredential $credential): WebhookEndpoint
    {
        if ($endpoint->isEnabled()) {
            $this->manager->disable($endpoint, self::RETIRED_REASON, $this->actor($credential));
        }

        return $endpoint->refresh();
    }

    /**
     * @return string The new secret, shown exactly once.
     */
    public function rotateSecret(
        WebhookEndpoint $endpoint,
        ?int $graceHours,
        ?ServiceCredential $credential,
    ): string {
        return $this->manager->rotateSecret($endpoint, $graceHours, $this->actor($credential));
    }

    /**
     * The credential's prefix, never its secret. The prefix is the public half of the key
     * and is safe in an audit payload (docs/operations/service-credentials.md).
     */
    private function actor(?ServiceCredential $credential): AuditActor
    {
        return AuditActor::system(
            $credential === null
                ? 'integration.native'
                : 'integration.native credential '.$credential->prefix,
        );
    }
}
