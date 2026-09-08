<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAssurancePolicyCheck;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Send is the last moment anything can be fixed cheaply, so the gate is strict and reports
 * everything at once.
 */
class EnvelopeSendGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_activates_the_first_stage_and_computes_the_expiry(): void
    {
        CarbonImmutable::setTestNow('2026-02-01T09:00:00Z');
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft();

        $result = $scenario->machine()->send($envelope);

        $this->assertSame(EnvelopeState::Draft, $result->from);
        $this->assertSame(EnvelopeState::Sent, $result->to);
        $this->assertSame([EnvelopeEvent::Sent->value], $result->eventNames());
        // Version 1 at creation, 2 for the sender's prefill, 3 for the send itself.
        $this->assertSame(3, $envelope->version);
        $this->assertSame('2026-02-08T09:00:00+00:00', $envelope->expires_at?->toIso8601String());

        $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'buyer')->state);
        $this->assertSame(RecipientState::Pending, $scenario->recipient($envelope, 'seller')->state);

        // Sequential mode does not freeze at send; the first acceptance does.
        $this->assertNull($envelope->content_frozen_at);
    }

    public function test_an_explicit_null_expiration_means_no_expiry(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft(['expiration_hours' => null]);

        $scenario->machine()->send($envelope);

        $this->assertNull($envelope->expires_at);
    }

    public function test_it_refuses_to_send_when_a_recipient_has_no_usable_email(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft();
        // The schema is immutable, so the row is what a broken import would leave behind.
        $scenario->recipient($envelope, 'seller')->forceFill(['email' => 'not-an-address'])->save();

        try {
            $scenario->machine()->send($envelope);
            $this->fail('An envelope with an unusable recipient address must not be sent.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertTrue($e->hasCode('recipient_missing_email'));
            $this->assertSame('seller', $e->problems[0]['recipient']);
        }

        $this->assertSame(EnvelopeState::Draft, $envelope->refresh()->state);
    }

    public function test_it_refuses_to_send_when_a_recipient_has_no_required_field(): void
    {
        $scenario = SigningScenario::create();
        $schema = SigningFixtures::mutateField(
            SigningFixtures::sequentialTwoSigners(),
            'seller_signature',
            ['required' => false],
        );
        $schema = SigningFixtures::mutateField($schema, 'seller_signed_at', ['required' => false]);
        $envelope = $scenario->preparedDraft(['field_schema' => $schema]);

        try {
            $scenario->machine()->send($envelope);
            $this->fail('A recipient with nothing required of them is not being asked for anything.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertTrue($e->hasCode('recipient_has_no_required_field'));
        }
    }

    public function test_it_refuses_to_send_when_a_required_read_only_field_has_no_value(): void
    {
        $scenario = SigningScenario::create();
        // A draft, deliberately without the sender prefills preparedDraft() would apply.
        $envelope = $scenario->draft();

        try {
            $scenario->machine()->send($envelope);
            $this->fail('Nobody can complete a required read-only field with no value.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertTrue($e->hasCode('required_read_only_field_missing_value'));
            $this->assertSame('agreement_effective_date', $e->problems[0]['field']);
        }
    }

    /**
     * The deadlock this gate exists to prevent: a material field its owner will never be
     * allowed to fill, because the first acceptance freezes the content before their turn.
     */
    public function test_it_refuses_a_required_material_field_owned_by_a_later_stage(): void
    {
        $scenario = SigningScenario::create();
        $schema = SigningFixtures::mutateField(
            SigningFixtures::sequentialTwoSigners(),
            'seller_notes',
            ['required' => true],
        );
        $envelope = $scenario->draft(['field_schema' => $schema]);
        $scenario->machine()->setSenderValues($envelope, ['agreement_effective_date' => '2026-01-31']);

        try {
            $scenario->machine()->send($envelope->refresh());
            $this->fail('A required material field owned by a later signer can never be completed.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertTrue($e->hasCode('required_material_field_unfillable'));
        }
    }

    /** In parallel mode the freeze is at send, so nobody at all can supply material values. */
    public function test_it_refuses_a_required_material_field_in_parallel_mode(): void
    {
        $scenario = SigningScenario::create();
        $schema = SigningFixtures::mutateField(
            SigningFixtures::parallelTwoSigners(),
            'shared_term',
            ['required' => true],
        );
        $envelope = $scenario->draft(['field_schema' => $schema, 'signing_mode' => 'parallel']);
        $scenario->machine()->setSenderValues($envelope, ['effective_date' => '2026-01-31']);

        try {
            $scenario->machine()->send($envelope->refresh());
            $this->fail('Parallel mode freezes at send, so a required material field must be supplied first.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertTrue($e->hasCode('required_material_field_unfillable'));
        }
    }

    public function test_a_supplied_value_satisfies_the_material_field_gate(): void
    {
        $scenario = SigningScenario::create();
        $schema = SigningFixtures::mutateField(
            SigningFixtures::parallelTwoSigners(),
            'shared_term',
            ['required' => true],
        );

        // preparedDraft() supplies exactly what the gate asks for, derived from the same rules.
        $envelope = $scenario->preparedDraft(['field_schema' => $schema, 'signing_mode' => 'parallel']);
        $scenario->machine()->send($envelope);

        $this->assertSame(EnvelopeState::Sent, $envelope->state);
    }

    public function test_it_refuses_to_send_when_the_assurance_material_is_unavailable(): void
    {
        $scenario = SigningScenario::create(
            FakeAssurancePolicyCheck::unavailable('No timestamp authority is configured.'),
        );
        $envelope = $scenario->preparedDraft(['assurance_level' => AssuranceLevel::PadesBT->value]);

        try {
            $scenario->machine()->send($envelope);
            $this->fail('A requested assurance level that cannot be met is an error, not a downgrade.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertTrue($e->hasCode('assurance_material_unavailable'));
            $this->assertStringContainsString('No timestamp authority is configured.', $e->getMessage());
        }

        $this->assertSame(AssuranceLevel::PadesBT, $scenario->assurance->asked[0]);
        $this->assertSame(EnvelopeState::Draft, $envelope->refresh()->state);
    }

    public function test_it_reports_every_problem_in_one_pass(): void
    {
        $scenario = SigningScenario::create(FakeAssurancePolicyCheck::unavailable());
        $envelope = $scenario->draft();
        $scenario->recipient($envelope, 'seller')->forceFill(['email' => ''])->save();

        try {
            $scenario->machine()->send($envelope);
            $this->fail('The gate must report everything at once.');
        } catch (SendPreconditionsFailed $e) {
            $this->assertEqualsCanonicalizing([
                'recipient_missing_email',
                'required_read_only_field_missing_value',
                'assurance_material_unavailable',
            ], $e->codes());
        }
    }

    public function test_parallel_mode_freezes_material_values_before_anyone_can_assent(): void
    {
        CarbonImmutable::setTestNow('2026-02-01T09:00:00Z');
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft([
            'field_schema' => SigningFixtures::parallelTwoSigners(),
            'signing_mode' => 'parallel',
        ]);
        $scenario->machine()->setSenderValues($envelope, ['shared_term' => 'The shared term as reviewed']);

        $scenario->machine()->send($envelope->refresh());

        $this->assertNotNull($envelope->content_frozen_at);
        $this->assertSame(
            [RecipientState::Active, RecipientState::Active],
            $envelope->recipients()->get()->pluck('state')->all(),
        );

        $material = EnvelopeFieldValue::query()
            ->where('envelope_id', $envelope->getKey())
            ->whereIn('schema_field_id', ['shared_term', 'effective_date'])
            ->get();

        $this->assertCount(2, $material);

        foreach ($material as $value) {
            $this->assertTrue($value->isFrozen(), $value->schema_field_id.' should be frozen at send.');
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
