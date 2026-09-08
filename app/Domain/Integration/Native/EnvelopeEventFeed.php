<?php

declare(strict_types=1);

namespace App\Domain\Integration\Native;

use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The outbox events of one envelope, oldest first.
 *
 * This is the pull half of the same feed webhooks push. An integration that cannot accept
 * inbound HTTP — or that missed a window while its receiver was down — reads the same event
 * rows here, in order, with a cursor it can persist.
 *
 * ## Why the payload is searched rather than a foreign key
 *
 * `outbox_events` has a workspace and an event name and no subject column: it is a generic
 * transactional outbox, and adding a nullable `envelope_id` to it would make one event
 * family privileged over every other. The envelope is identified inside the event's own
 * payload instead.
 *
 * The canonical location is `signing_request.id`, which is where
 * App\Domain\Delivery\Events\SigningRequestPayload puts it and what every delivered body
 * carries (docs/delivery/envelope-events.md). Two flatter spellings are matched as well:
 * `envelope`, which the Signing module's audit sink writes, and `signing_request_id`, which
 * the compatibility profile uses for the same fact. Matching all three means an event is
 * found whichever producer recorded it, and a producer added later only has to use one of
 * them rather than being coupled to this file.
 *
 * The workspace predicate comes first and is not negotiable: it is an indexed column, and it
 * means a JSON comparison never runs against another tenant's rows.
 */
final readonly class EnvelopeEventFeed
{
    /**
     * The JSON paths inside a payload that may carry an envelope's public id, canonical
     * first.
     *
     * @var list<string>
     */
    public const ENVELOPE_PATHS = ['signing_request->id', 'envelope', 'signing_request_id'];

    /**
     * @return Page<OutboxEvent>
     *
     * @throws ApiException On a cursor this API did not issue.
     */
    public function forEnvelope(Workspace $workspace, Envelope $envelope, ?string $cursor, int $limit): Page
    {
        $query = OutboxEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where(function (Builder $inner) use ($envelope): void {
                foreach (self::ENVELOPE_PATHS as $path) {
                    $inner->orWhere('payload->'.$path, $envelope->public_id);
                }
            });

        return Page::keyset($query, $cursor, $limit);
    }
}
