<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Delivery\Events\CompositeEnvelopeEventSink;
use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Webhooks\WebhookEventName;
use App\Domain\Evidence\Finalization\FinalizationTrigger;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Signing\Contracts\EnvelopeEventSink;
use App\Domain\Signing\Envelopes\AuditEnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Envelopes\NullEnvelopeEventSink;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeAssurancePolicyCheck;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Events are part of the transition, not a consequence of it.
 *
 * The sink is called inside the transaction that made the change, so an event and its state
 * change commit or roll back together (docs/HANDOFF.md section 11).
 */
class EnvelopeEventSinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_binding_records_locally_and_publishes_to_the_outbox(): void
    {
        $sink = app(EnvelopeEventSink::class);

        // The audit store is not replaced by the webhook outbox: an event that exists only
        // as a delivery leaves no local history the moment an endpoint is disabled.
        $this->assertInstanceOf(CompositeEnvelopeEventSink::class, $sink);

        // FinalizationTrigger is last, and it is the only member that is not a database
        // write: it defers its dispatch to after the commit, so a throw from either sink in
        // front of it aborts the transition before anything has been queued (issue #94).
        $this->assertSame(
            [AuditEnvelopeEventSink::class, DeliveryEnvelopeEventSink::class, FinalizationTrigger::class],
            array_map(static fn (EnvelopeEventSink $member): string => $member::class, $sink->sinks()),
        );
    }

    public function test_a_full_lifecycle_publishes_the_documented_event_names(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));
        $scenario->machine()->markCompleted($envelope->refresh(), 'artifacts/synthetic.pdf');

        $this->assertSame([
            'signing_request.created',
            'signing_request.sent',
            'signing_request.recipient.signed',
            'signing_request.completed',
        ], $scenario->sink->names());
    }

    public function test_declining_publishes_the_recipient_and_the_envelope_event(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        $scenario->machine()->decline($scenario->recipient($envelope, 'signer'), 'No.');

        $this->assertSame([
            'signing_request.created',
            'signing_request.sent',
            'signing_request.recipient.declined',
            'esign.envelope.declined',
        ], $scenario->sink->names());
    }

    public function test_cancelling_and_expiring_publish_the_profile_names(): void
    {
        $scenario = SigningScenario::create();
        $cancelled = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $scenario->machine()->cancel($cancelled, 'Withdrawn.');

        $this->assertContains('signing_request.cancelled', $scenario->sink->names());

        $expiring = SigningScenario::create();
        $envelope = $expiring->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $this->travel(30)->days();
        $expiring->machine()->expire($envelope->refresh());
        $this->travelBack();

        $this->assertContains('signing_request.expired', $expiring->sink->names());
    }

    public function test_a_failed_finalization_publishes_our_own_namespaced_event(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));
        $scenario->machine()->markFinalizationFailed($envelope->refresh(), 'Sealing timed out.');

        $this->assertContains('esign.envelope.finalization.failed', $scenario->sink->names());
        $this->assertNotContains('signing_request.completed', $scenario->sink->names());
    }

    /**
     * The property that matters: rolling back the transition removes the event with it.
     *
     * A sink that queued a delivery, sent mail, or called an endpoint could not have this
     * property, which is why the port requires an implementation to be a database write and
     * nothing else.
     */
    public function test_rolling_back_a_transition_removes_both_the_change_and_the_event(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft(['field_schema' => SigningFixtures::singleSigner()]);
        $machine = new EnvelopeStateMachine(app(EnvelopeEventSink::class), $scenario->assurance);

        AuditEvent::query()->delete();

        try {
            DB::transaction(function () use ($machine, $envelope): void {
                $machine->send($envelope);

                $this->assertSame(EnvelopeState::Sent, Envelope::query()->findOrFail($envelope->getKey())->state);
                $this->assertSame(1, AuditEvent::query()->where('action', EnvelopeEvent::Sent->value)->count());

                throw new RuntimeException('Something later in the request failed.');
            });
            $this->fail('The transaction should have rolled back.');
        } catch (RuntimeException $e) {
            $this->assertSame('Something later in the request failed.', $e->getMessage());
        }

        $this->assertSame(EnvelopeState::Draft, Envelope::query()->findOrFail($envelope->getKey())->state);
        $this->assertSame(0, AuditEvent::query()->where('action', EnvelopeEvent::Sent->value)->count());
    }

    public function test_the_audit_sink_records_the_envelope_as_the_subject(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft(['field_schema' => SigningFixtures::singleSigner()]);
        // The audit sink on its own. The container's default composes it with the webhook
        // outbox, which schedules mail and belongs to the Delivery suite; this test is about
        // what one row in the audit store says.
        $machine = new EnvelopeStateMachine(app(AuditEnvelopeEventSink::class), $scenario->assurance);

        $machine->send($envelope);

        $event = AuditEvent::query()->where('action', EnvelopeEvent::Sent->value)->sole();

        $this->assertSame('system', $event->actor_type);
        $this->assertSame(Envelope::class, $event->subject_type);
        $this->assertSame((string) $envelope->getKey(), $event->subject_id);
        $this->assertSame($envelope->public_id, $event->payload['envelope']);
        $this->assertSame('sequential', $event->payload['signing_mode']);
    }

    public function test_the_null_sink_records_nothing_and_is_never_the_default(): void
    {
        $scenario = SigningScenario::create();
        $machine = new EnvelopeStateMachine(new NullEnvelopeEventSink, FakeAssurancePolicyCheck::available());
        $envelope = $scenario->preparedDraft(['field_schema' => SigningFixtures::singleSigner()]);

        AuditEvent::query()->delete();
        $machine->send($envelope);

        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    /**
     * The other side of the seam.
     *
     * The webhook outbox refuses an event name it does not recognise rather than recording
     * one no receiver handles, so every name this module publishes has to be one the outbox
     * would accept — otherwise wiring the two together (issue #29) fails at runtime on the
     * first decline. Asserted here rather than assumed, and without either module depending
     * on the other at run time.
     */
    public function test_every_event_name_is_one_the_webhook_outbox_would_accept(): void
    {
        foreach (EnvelopeEvent::cases() as $event) {
            $this->assertTrue(
                WebhookEventName::isKnown($event->value),
                $event->value.' would be refused by the webhook outbox.',
            );
        }
    }

    public function test_every_declared_event_name_is_either_a_profile_name_or_namespaced(): void
    {
        $matrix = (string) file_get_contents(base_path('docs/compatibility/firma-capability-matrix.md'));

        foreach (EnvelopeEvent::cases() as $event) {
            if (str_starts_with($event->value, 'esign.')) {
                continue;
            }

            $this->assertStringContainsString(
                '`'.$event->value.'`',
                $matrix,
                $event->value.' claims to be a Firma profile event but the capability matrix does not list it.',
            );
        }
    }
}
