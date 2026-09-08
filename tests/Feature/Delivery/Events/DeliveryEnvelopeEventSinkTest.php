<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Events;

use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\WebhookEventName;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Signing\Envelopes\EnvelopeFactory;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\Envelope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeDownloadUrlMinter;
use Tests\Support\FakeSigningUrlMinter;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * What a transition puts on the wire, and when.
 *
 * The two halves of the contract are tested as one thing because their relationship is the
 * point: the webhook event is written *inside* the transition's transaction and the mail is
 * scheduled *outside* it. A test that faked the queue could not tell the difference, so this
 * suite runs the scheduling job on the synchronous queue — which honours `afterCommit()` —
 * and fakes only the two jobs that would reach a network.
 */
class DeliveryEnvelopeEventSinkTest extends TestCase
{
    use RefreshDatabase;

    private SigningScenario $scenario;

    private FakeSigningUrlMinter $signingUrls;

    /**
     * Rows written before the transition under test. Nothing is deleted to make room —
     * `outbound_mails` is referenced by its own event log, and a test that truncated it
     * would be asserting against a history the application never has.
     */
    private int $eventFloor = 0;

    private int $mailFloor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Neither of these leaves the process: SendOutboundMail would hand bytes to a
        // transport and DispatchOutboxEvent would fan out to endpoints. Everything between
        // the transition and the outbox rows runs for real.
        Queue::fake([SendOutboundMail::class, DispatchOutboxEvent::class]);

        $this->scenario = SigningScenario::create();
        $this->signingUrls = new FakeSigningUrlMinter;

        $this->app->instance(SigningUrlMinter::class, $this->signingUrls);
        $this->app->instance(DownloadUrlMinter::class, new FakeDownloadUrlMinter);
    }

    public function test_creating_an_envelope_records_the_created_event_and_tells_nobody(): void
    {
        $envelope = $this->factory()->fromSnapshot(
            $this->scenario->workspace,
            $this->scenario->snapshot(),
            $this->scenario->user,
        );

        $event = $this->onlyEvent('signing_request.created');

        $this->assertSame($envelope->public_id, $event->payload['signing_request']['id']);
        $this->assertFalse($event->payload['signing_request']['status']['sent']);

        // A draft has been shown to nobody. Announcing it would be the first thing a
        // recipient hears about an agreement that may never be sent.
        $this->assertSame(0, OutboundMail::query()->count());
    }

    public function test_sending_records_one_event_and_invites_only_the_released_stage(): void
    {
        $envelope = $this->scenario->preparedDraft();

        $this->machine()->send($envelope);

        $event = $this->onlyEvent('signing_request.sent');
        $payload = $event->payload;

        $this->assertSame($envelope->public_id, $payload['signing_request']['id']);
        $this->assertTrue($payload['signing_request']['status']['sent']);
        $this->assertFalse($payload['signing_request']['status']['finished']);
        $this->assertNotNull($payload['signing_request']['timestamps']['sent_on']);
        $this->assertNull($payload['signing_request']['download']);
        $this->assertSame($this->scenario->workspace->public_id, $payload['workspace']['id']);

        // The envelope-scoped event describes everybody; the sequential invitation does not.
        $this->assertCount(2, $payload['recipients']);
        $this->assertSame(['buyer@example.test'], $this->queuedTo(MailKind::Invitation));
    }

    public function test_the_event_commits_with_the_transition_and_the_mail_only_after_it(): void
    {
        $envelope = $this->scenario->preparedDraft();

        DB::transaction(function () use ($envelope): void {
            $this->machine()->send($envelope);

            // Inside the caller's transaction: the outbox row is already there, because it
            // has to roll back with the transition if this closure throws.
            $this->assertSame(1, OutboxEvent::query()->count());

            // And the message is not, because mail has no undo.
            $this->assertSame(0, OutboundMail::query()->count());
        });

        $this->assertSame(1, OutboundMail::query()->count());
    }

    public function test_a_rolled_back_transition_leaves_no_event_and_no_mail(): void
    {
        $envelope = $this->scenario->preparedDraft();

        try {
            DB::transaction(function () use ($envelope): void {
                $this->machine()->send($envelope);

                throw new RuntimeException('The caller failed after the transition.');
            });
            $this->fail('The transaction should have been rolled back.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, OutboxEvent::query()->count());
        $this->assertSame(0, OutboundMail::query()->count());
        $this->assertSame([], $this->signingUrls->minted);
    }

    public function test_an_acceptance_is_a_recipient_event_and_never_a_completion(): void
    {
        $envelope = $this->sentEnvelope();

        $this->signAs($envelope, 'buyer');

        $event = $this->onlyEvent('signing_request.recipient.signed');
        $buyer = $this->scenario->recipient($envelope, 'buyer');

        // Recipient-scoped: the one party the event is about, with their finished_on.
        $this->assertCount(1, $event->payload['recipients']);
        $this->assertSame($buyer->public_id, $event->payload['recipients'][0]['id']);
        $this->assertNotNull($event->payload['recipients'][0]['finished_on']);

        // A signature is not an execution. docs/HANDOFF.md §11.
        $this->assertFalse($event->payload['signing_request']['status']['finished']);
        $this->assertNull($event->payload['signing_request']['timestamps']['finished_on']);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'signing_request.completed')->count());

        // And it invites the next stage, and only the next stage.
        $this->assertSame(['seller@example.test'], $this->queuedTo(MailKind::Invitation));
        $this->assertSame([], $this->queuedTo(MailKind::Completed));
    }

    public function test_completion_is_recorded_once_the_artifact_reference_exists(): void
    {
        $envelope = $this->sentEnvelope();
        $this->signAs($envelope, 'buyer');
        $this->signAs($envelope, 'seller');

        // Everyone has signed and the envelope is finalizing. Completion is what the
        // finalizer asserts *after* that, and it is the only event this block is about.
        $this->eventFloor = (int) OutboxEvent::query()->max('id');
        $this->mailFloor = (int) OutboundMail::query()->max('id');

        $this->machine()->markCompleted($envelope->refresh(), 'artifacts/'.$envelope->public_id.'/final.pdf');

        $event = $this->onlyEvent('signing_request.completed');
        $request = $event->payload['signing_request'];

        $this->assertTrue($request['status']['finished']);
        $this->assertNotNull($request['timestamps']['finished_on']);
        $this->assertTrue($request['download']['available']);

        // Every party, and the sender.
        $this->assertEqualsCanonicalizing(
            ['buyer@example.test', 'seller@example.test', $this->scenario->user->email],
            $this->queuedTo(MailKind::Completed),
        );
    }

    public function test_a_decline_records_both_names_and_writes_only_to_the_sender(): void
    {
        $envelope = $this->sentEnvelope();
        $buyer = $this->scenario->recipient($envelope, 'buyer');

        $this->machine()->decline($buyer, 'The signatory named in the document has left.');

        // The profile has a recipient-level decline and no envelope-level one (D12), so both
        // names go out: theirs for a subscriber, ours for anything that needs the envelope
        // fact without inferring it.
        $this->assertSame(
            ['signing_request.recipient.declined', 'esign.envelope.declined'],
            $this->newEventNames(),
        );

        $recipientEvent = OutboxEvent::query()->where('event_name', 'signing_request.recipient.declined')->sole();
        $this->assertCount(1, $recipientEvent->payload['recipients']);
        $this->assertSame($buyer->public_id, $recipientEvent->payload['recipients'][0]['id']);

        // One notice, not two: the mail belongs to the envelope-level event.
        $this->assertSame([$this->scenario->user->email], $this->queuedTo(MailKind::Declined));
    }

    public function test_a_cancellation_reaches_everybody_who_had_been_written_to(): void
    {
        $envelope = $this->sentEnvelope();

        $this->machine()->cancel($envelope, 'Superseded by a revised draft.');

        $this->onlyEvent('signing_request.cancelled');

        // The seller was never invited — in sequential mode their stage had not opened — so
        // they are not told about an agreement they were never shown.
        $this->assertEqualsCanonicalizing(
            ['buyer@example.test', $this->scenario->user->email],
            $this->queuedTo(MailKind::Cancelled),
        );
    }

    public function test_an_expiry_reaches_everybody_who_had_been_written_to(): void
    {
        $envelope = $this->sentEnvelope();

        $this->travel(($envelope->expiration_hours ?? 168) + 1)->hours();

        $this->machine()->expire($envelope->refresh());

        $event = $this->onlyEvent('signing_request.expired');

        $this->assertTrue($event->payload['signing_request']['status']['expired']);
        $this->assertTrue($event->payload['signing_request']['status']['sent']);

        $this->assertEqualsCanonicalizing(
            ['buyer@example.test', $this->scenario->user->email],
            $this->queuedTo(MailKind::Expired),
        );
    }

    public function test_a_finalization_failure_alerts_the_workspace_owners_and_no_signer(): void
    {
        $owner = User::factory()->create();
        WorkspaceMembership::query()->create([
            'workspace_id' => $this->scenario->workspace->getKey(),
            'user_id' => $owner->getKey(),
            'role' => WorkspaceRole::Owner->value,
        ]);

        $envelope = $this->sentEnvelope();
        $this->signAs($envelope, 'buyer');
        $this->signAs($envelope, 'seller');

        $this->mailFloor = (int) OutboundMail::query()->max('id');
        $this->eventFloor = (int) OutboxEvent::query()->max('id');

        $this->machine()->markFinalizationFailed($envelope->refresh(), 'The timestamp authority did not answer.');

        $event = $this->onlyEvent('esign.envelope.finalization.failed');

        // Not a completion, and not describable as one.
        $this->assertFalse($event->payload['signing_request']['status']['finished']);

        // Operators, not signers: from a signer's side the agreement is signed and waiting,
        // and a retry that succeeds needs no correction to have been mailed.
        $this->assertSame([$owner->email], $this->queuedTo(MailKind::AdminFailure));
        $this->assertSame([], $this->queuedTo(MailKind::Completed));
    }

    public function test_every_name_this_sink_emits_is_one_the_outbox_accepts(): void
    {
        $envelope = $this->sentEnvelope();
        $this->machine()->cancel($envelope);

        $names = OutboxEvent::query()->pluck('event_name');

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertTrue(WebhookEventName::isKnown($name), $name.' would be refused by the outbox.');
        }
    }

    private function sentEnvelope(): Envelope
    {
        $envelope = $this->scenario->preparedDraft();
        $this->machine()->send($envelope);

        $this->eventFloor = (int) OutboxEvent::query()->max('id');
        $this->mailFloor = (int) OutboundMail::query()->max('id');

        return $envelope->refresh();
    }

    private function signAs(Envelope $envelope, string $schemaRecipientId): void
    {
        $recipient = $this->scenario->recipient($envelope, $schemaRecipientId);
        $this->scenario->completeRequiredFieldsFor($envelope->refresh(), $recipient);

        $this->machine()->accept($recipient->refresh(), $this->scenario->acceptanceRequest($envelope));
    }

    /**
     * @return list<string>
     */
    private function queuedTo(MailKind $kind): array
    {
        return OutboundMail::query()
            ->where('kind', $kind->value)
            ->where('id', '>', $this->mailFloor)
            ->orderBy('id')
            ->pluck('to_email')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function newEventNames(): array
    {
        return OutboxEvent::query()->where('id', '>', $this->eventFloor)->orderBy('id')->pluck('event_name')->all();
    }

    private function onlyEvent(string $name): OutboxEvent
    {
        $this->assertSame([$name], $this->newEventNames());

        return OutboxEvent::query()->where('id', '>', $this->eventFloor)->sole();
    }

    private function machine(): EnvelopeStateMachine
    {
        return new EnvelopeStateMachine(
            $this->app->make(DeliveryEnvelopeEventSink::class),
            $this->scenario->assurance,
        );
    }

    private function factory(): EnvelopeFactory
    {
        return new EnvelopeFactory($this->app->make(DeliveryEnvelopeEventSink::class));
    }
}
