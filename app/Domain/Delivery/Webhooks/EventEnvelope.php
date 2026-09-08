<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Webhooks;

use Carbon\CarbonImmutable;

/**
 * The JSON body of a webhook, encoded once when the event is recorded.
 *
 * Shape follows the profile envelope
 * (docs/compatibility/firma-capability-matrix.md, "Envelope and delivery"):
 * `{id, type, created_at, workspace_id, data}`. Event identity is `id`, the
 * name the upstream guide's envelope and its idempotency example both use;
 * disagreement D13 records that the consumer also accepts `event_id`, and we do
 * not emit that second spelling.
 *
 * `company_id` is documented upstream and is omitted here: this product has no
 * company above the workspace, and emitting a null or an invented value would
 * be worse than its absence.
 *
 * The encoding flags are fixed because the bytes are what gets signed.
 */
final class EventEnvelope
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(
        string $eventId,
        string $eventName,
        string $workspacePublicId,
        array $payload,
        CarbonImmutable $occurredAt,
    ): string {
        return json_encode([
            'id' => $eventId,
            'type' => $eventName,
            'created_at' => $occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'workspace_id' => $workspacePublicId,
            'data' => $payload === [] ? (object) [] : $payload,
        ], self::JSON_FLAGS);
    }
}
