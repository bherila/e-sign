<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Events;

use App\Domain\Delivery\Events\Jobs\ScheduleEnvelopeMail;
use App\Domain\Delivery\Webhooks\OutboxWriter;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeRecipient;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * The join between the signing state machine and the two outboxes.
 *
 * Two things happen per event, and the difference between them is the whole design:
 *
 * 1. **The webhook event is recorded inside the caller's transaction.** `OutboxWriter` refuses
 *    to be called with no transaction open, which is the guarantee that an event and the
 *    transition it describes commit or roll back together (`docs/HANDOFF.md` §11). A
 *    rolled-back acceptance leaves no `recipient.signed` on anyone's wire.
 * 2. **Mail is scheduled after commit.** {@see ScheduleEnvelopeMail} is dispatched with
 *    `->afterCommit()`, so a queue worker cannot pick it up before the transition is durable
 *    and cannot pick it up at all if the transition is rolled back. Mail has no undo, and an
 *    invitation sent for an envelope that never left `draft` cannot be recalled.
 *
 * Nothing here talks to a network, opens a socket, or renders a template. That is what
 * {@see EnvelopeEventSink} requires of an implementation: it is called while the envelope row
 * is locked, so anything slow or fallible here would either block the transition or fail it.
 *
 * The payload is {@see SigningRequestPayload}, the same builder the Firma-compatible facade
 * uses for `GET /signing-requests/{id}`, so a receiver polling and a receiver subscribing
 * cannot be told two different stories about the same envelope.
 *
 * This sink is not a replacement for `App\Domain\Signing\Envelopes\AuditEnvelopeEventSink`.
 * An event that exists only as a webhook delivery leaves no local history the moment an
 * endpoint is disabled, so both are bound through {@see CompositeEnvelopeEventSink}.
 */
final readonly class DeliveryEnvelopeEventSink implements EnvelopeEventSink
{
    public function __construct(
        private OutboxWriter $outbox,
        private Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  The state machine's own minimized facts. Used
     *                                         to locate the recipient a recipient-scoped event
     *                                         is about; never forwarded verbatim, because the
     *                                         wire shape is the profile's, not ours.
     */
    public function record(Envelope $envelope, EnvelopeEvent $event, array $payload = []): void
    {
        $workspace = $envelope->workspace;

        if ($workspace === null) {
            // envelopes.workspace_id is a non-nullable, restrict-on-delete foreign key, so
            // this means the row was read without its workspace still existing. Refusing is
            // the only safe answer: an event with no workspace has no endpoints to reach and
            // no tenant to be scoped by.
            throw new RuntimeException(
                'Envelope '.$envelope->public_id.' has no workspace; refusing to record '.$event->value.'.'
            );
        }

        $this->outbox->record(
            $workspace,
            $event->value,
            $this->data($envelope, $event, $payload),
        );

        if (! $this->schedulesMail($event)) {
            return;
        }

        ScheduleEnvelopeMail::dispatch($envelope->public_id, $event->value)
            ->onQueue((string) $this->config->get('esign.mail.queue', 'mail'))
            ->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function data(Envelope $envelope, EnvelopeEvent $event, array $payload): array
    {
        $recipient = $this->actingRecipient($envelope, $event, $payload);

        return $recipient === null
            ? SigningRequestPayload::for($envelope)
            : SigningRequestPayload::forRecipient($envelope, $recipient);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function actingRecipient(Envelope $envelope, EnvelopeEvent $event, array $payload): ?EnvelopeRecipient
    {
        if (! in_array($event, [EnvelopeEvent::RecipientSigned, EnvelopeEvent::RecipientDeclined], true)) {
            return null;
        }

        $publicId = $payload['recipient'] ?? null;

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return $envelope->recipients()->where('public_id', $publicId)->first();
    }

    /**
     * Events that produce no message are not queued for one.
     *
     * `signing_request.created` tells nobody anything — a draft has not been sent — and
     * `signing_request.recipient.declined` always arrives beside `esign.envelope.declined`,
     * which is where the notice belongs. Dispatching a job that would decide to do nothing
     * would put two rows on the mail queue for every decline and make the queue depth a
     * misleading number.
     */
    private function schedulesMail(EnvelopeEvent $event): bool
    {
        return ! in_array($event, [EnvelopeEvent::Created, EnvelopeEvent::RecipientDeclined], true);
    }
}
