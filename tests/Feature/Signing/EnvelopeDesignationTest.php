<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Preparation\Schema\RecipientDesignation;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Approvers and cc recipients on a live envelope (field schema 1.2, issue #169).
 *
 * An approver acts in signing order exactly as a signer does, and their acceptance is an
 * attestation in the approver role. A cc recipient is outside the order altogether: `copied`
 * from creation, never released, never counted as outstanding, and never able to act.
 */
class EnvelopeDesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_envelope_records_each_partys_designation(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->draft(['field_schema' => self::schemaWithApproverAndCc()]);

        $designations = $envelope->recipients()->orderBy('id')->get()
            ->mapWithKeys(static fn ($recipient): array => [$recipient->schema_recipient_id => $recipient->designation])
            ->all();

        ksort($designations);

        $this->assertSame([
            'manager' => RecipientDesignation::Approver,
            'records' => RecipientDesignation::Cc,
            'signer' => RecipientDesignation::Signer,
        ], $designations);

        $snapshot = $envelope->recipients()->where('schema_recipient_id', 'manager')->firstOrFail()->identity_snapshot;
        $this->assertSame('approver', $snapshot['designation']);
    }

    public function test_a_cc_recipient_is_copied_from_creation_and_outside_every_stage(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->draft(['field_schema' => self::schemaWithApproverAndCc()]);
        $records = $scenario->recipient($envelope, 'records');

        $this->assertSame(RecipientState::Copied, $records->state);
        $this->assertSame(0, $records->order_index);
        $this->assertSame(2, $scenario->recipient($envelope, 'manager')->order_index);
    }

    public function test_the_approver_acts_in_order_and_the_cc_recipient_never_does(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => self::schemaWithApproverAndCc()]);

        $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'signer')->state);
        $this->assertSame(RecipientState::Pending, $scenario->recipient($envelope, 'manager')->state);
        $this->assertSame(RecipientState::Copied, $scenario->recipient($envelope, 'records')->state);

        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'), 'session-signer');

        $this->assertSame(EnvelopeState::InProgress, $envelope->refresh()->state);
        $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'manager')->state);
        $this->assertSame(RecipientState::Copied, $scenario->recipient($envelope, 'records')->state);

        $approval = $scenario->signAs($envelope, $scenario->recipient($envelope, 'manager'), 'session-manager');

        // The cc recipient never answered and is not waited for.
        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
        $this->assertSame(RecipientState::Signed, $scenario->recipient($envelope, 'manager')->state);
        $this->assertSame(RecipientState::Copied, $scenario->recipient($envelope, 'records')->state);
        $this->assertSame($scenario->recipient($envelope, 'manager')->getKey(), $approval->attestation->recipient_id);
    }

    public function test_a_cc_recipient_can_neither_accept_nor_decline(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => self::schemaWithApproverAndCc()]);
        $records = $scenario->recipient($envelope, 'records');

        try {
            $scenario->machine()->accept($records, $scenario->acceptanceRequest($envelope, 'session-records'));
            $this->fail('A cc recipient must not be able to accept.');
        } catch (RecipientNotEligible $refusal) {
            $this->assertStringContainsString('copied on the agreement', $refusal->getMessage());
        }

        try {
            $scenario->machine()->decline($records->refresh(), 'Not mine to decide.');
            $this->fail('A cc recipient must not be able to decline.');
        } catch (RecipientNotEligible) {
            // Refused, and the envelope is untouched.
        }

        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
    }

    public function test_an_approver_may_decline_like_a_signer(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => self::schemaWithApproverAndCc()]);
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'), 'session-signer');

        $scenario->machine()->decline($scenario->recipient($envelope, 'manager'), 'Budget not approved.');

        $this->assertSame(EnvelopeState::Declined, $envelope->refresh()->state);
        $this->assertSame(RecipientState::Declined, $scenario->recipient($envelope, 'manager')->state);
    }

    public function test_in_parallel_mode_the_cc_recipient_is_not_released_with_everyone_else(): void
    {
        $schema = self::schemaWithApproverAndCc();
        $schema['signing_order'] = [['signer', 'manager']];

        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => $schema, 'signing_mode' => 'parallel']);

        $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'signer')->state);
        $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'manager')->state);
        $this->assertSame(RecipientState::Copied, $scenario->recipient($envelope, 'records')->state);
    }

    /**
     * A draft created before the signing side of #169 stored its cc recipient as `pending` in
     * stage 1. Sending it must not release them.
     */
    public function test_a_cc_recipient_stored_the_earlier_way_is_copied_at_send_rather_than_released(): void
    {
        $schema = self::schemaWithApproverAndCc();
        $schema['signing_order'] = [['signer', 'manager']];

        foreach (['sequential', 'parallel'] as $mode) {
            $scenario = SigningScenario::create();
            $envelope = $scenario->preparedDraft(['field_schema' => $schema, 'signing_mode' => $mode]);
            $scenario->recipient($envelope, 'records')
                ->forceFill(['state' => RecipientState::Pending, 'order_index' => 1])
                ->save();

            $scenario->machine()->send($envelope->refresh());

            $records = $scenario->recipient($envelope, 'records');
            $this->assertSame(RecipientState::Copied, $records->state, $mode);
            $this->assertSame(0, $records->order_index, $mode);
            $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'signer')->state, $mode);
        }
    }

    public function test_a_cc_recipient_needs_an_address_for_the_completed_copy(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->preparedDraft(['field_schema' => self::schemaWithApproverAndCc()]);
        $scenario->recipient($envelope, 'records')->forceFill(['email' => 'not-an-address'])->save();

        try {
            $scenario->machine()->send($envelope->refresh());
            $this->fail('A cc recipient with no usable address must stop the send.');
        } catch (SendPreconditionsFailed $failure) {
            $codes = array_column($failure->problems, 'code', 'recipient');

            $this->assertSame(['records' => 'recipient_missing_email'], $codes);
        }

        $this->assertSame(EnvelopeState::Draft, $envelope->refresh()->state);
    }

    /** @return array<string, mixed> */
    public static function schemaWithApproverAndCc(): array
    {
        $schema = SigningFixtures::singleSigner();
        $schema['schema_version'] = '1.2';
        $schema['recipients'][] = ['id' => 'manager', 'name' => 'Synthetic Manager', 'email' => 'manager@example.test', 'designation' => 'approver'];
        $schema['recipients'][] = ['id' => 'records', 'name' => 'Synthetic Records', 'email' => 'records@example.test', 'designation' => 'cc'];
        $schema['signing_order'] = [['signer'], ['manager']];
        $schema['fields'][] = [
            'id' => 'manager_approval',
            'recipient_id' => 'manager',
            'type' => 'approval_checkmark',
            'page' => 1,
            'rect' => ['x' => 300, 'y' => 650, 'width' => 24, 'height' => 24],
            'required' => true,
        ];
        $schema['fields'][] = [
            'id' => 'manager_signature',
            'recipient_id' => 'manager',
            'type' => 'approval_signature',
            'page' => 1,
            'rect' => ['x' => 330, 'y' => 650, 'width' => 170, 'height' => 36],
            'required' => true,
        ];
        $schema['fields'][] = [
            'id' => 'manager_approved_on',
            'recipient_id' => 'manager',
            'type' => 'approval_date',
            'page' => 1,
            'rect' => ['x' => 330, 'y' => 700, 'width' => 170, 'height' => 18],
            'required' => true,
            'read_only' => true,
        ];

        return $schema;
    }
}
