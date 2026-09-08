<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

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

    public function test_the_default_binding_is_the_audit_sink(): void
    {
        $this->assertInstanceOf(AuditEnvelopeEventSink::class, app(EnvelopeEventSink::class));
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
        $machine = new EnvelopeStateMachine(app(EnvelopeEventSink::class), $scenario->assurance);

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
