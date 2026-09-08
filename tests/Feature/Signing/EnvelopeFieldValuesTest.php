<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\ValueSource;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use App\Domain\Signing\Fields\CanonicalValue;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Invariant 1 (own fields, while eligible) and the second half of invariant 4 (after the
 * freeze, signer-specific fields of unsigned recipients only).
 */
class EnvelopeFieldValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recipient_can_complete_their_own_fields(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        $result = $scenario->machine()->submitValues($buyer, [
            'buyer_ack' => true,
            'buyer_notes' => 'Reviewed and understood.',
        ]);

        $this->assertSame(['buyer_ack', 'buyer_notes'], $result->fieldIds);
        $this->assertSame(EnvelopeState::InProgress, $result->envelope->state);
        $this->assertSame($result->envelope->version, $result->envelopeVersion);

        $stored = EnvelopeFieldValue::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('schema_field_id', 'buyer_ack')
            ->sole();

        $this->assertTrue($stored->value);
        $this->assertSame(ValueSource::Recipient, $stored->set_by);
        $this->assertSame($buyer->getKey(), $stored->recipient_id);
        $this->assertSame(CanonicalValue::digest(true), $stored->value_sha256);
        $this->assertFalse($stored->isFrozen());
    }

    public function test_a_recipient_cannot_submit_another_recipients_field(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        try {
            $scenario->machine()->submitValues($buyer, ['seller_title' => 'Director']);
            $this->fail('Field ownership is by recipient id and must be enforced server-side.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_not_owned', $e->code());
            $this->assertSame('seller_title', $e->fieldId);
        }

        $this->assertSame(0, EnvelopeFieldValue::query()->where('schema_field_id', 'seller_title')->count());
    }

    public function test_a_recipient_cannot_submit_an_unknown_field(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();

        try {
            $scenario->machine()->submitValues(
                $scenario->recipient($envelope, 'buyer'),
                ['buyer_secret_backdoor' => 'anything'],
            );
            $this->fail('A field outside the copied schema does not exist for this envelope.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('unknown_field', $e->code());
        }
    }

    public function test_a_recipient_cannot_overwrite_a_read_only_field(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();

        try {
            $scenario->machine()->submitValues(
                $scenario->recipient($envelope, 'buyer'),
                ['agreement_effective_date' => '2030-01-01'],
            );
            $this->fail('A read-only field is not the recipient\'s to complete.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_read_only', $e->code());
        }

        $this->assertSame(
            '2026-01-31',
            EnvelopeFieldValue::query()->where('schema_field_id', 'agreement_effective_date')->sole()->value,
        );
    }

    public function test_a_service_supplied_field_cannot_be_submitted_by_anyone(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->signAs($envelope, $buyer);
        $seller = $scenario->recipient($envelope->refresh(), 'seller');

        try {
            $scenario->machine()->submitValues($seller, ['seller_signed_at' => '2020-01-01']);
            $this->fail('A client must not be able to state when it signed.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_service_supplied', $e->code());
        }

        try {
            $scenario->machine()->setSenderValues($envelope->refresh(), ['seller_signed_at' => '2020-01-01']);
            $this->fail('Nor may the sender.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_service_supplied', $e->code());
        }
    }

    public function test_a_pending_recipient_cannot_submit_anything(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $seller = $scenario->recipient($envelope, 'seller');

        $this->expectException(RecipientNotEligible::class);
        $this->expectExceptionMessageMatches('/earlier signing stage has not finished/');

        $scenario->machine()->submitValues($seller, ['seller_title' => 'Director']);
    }

    public function test_values_are_checked_against_the_fields_declared_type(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        foreach ([
            ['buyer_ack' => 'yes'],
            ['buyer_notes' => ['an', 'array']],
            ['buyer_notes' => ''],
            ['buyer_signature' => 42],
        ] as $submission) {
            try {
                $scenario->machine()->submitValues($buyer->refresh(), $submission);
                $this->fail('Expected '.json_encode($submission).' to be refused.');
            } catch (FieldSubmissionRejected $e) {
                $this->assertSame('invalid_value', $e->code());
            }
        }
    }

    public function test_a_sender_may_prefill_a_read_only_field_but_never_a_signature(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->draft();

        $scenario->machine()->setSenderValues($envelope, ['agreement_effective_date' => '2026-01-31']);

        $stored = EnvelopeFieldValue::query()->where('schema_field_id', 'agreement_effective_date')->sole();
        $this->assertSame(ValueSource::Sender, $stored->set_by);
        // The owning recipient is recorded even though the sender wrote the value.
        $this->assertSame($scenario->recipient($envelope, 'buyer')->getKey(), $stored->recipient_id);

        try {
            $scenario->machine()->setSenderValues($envelope->refresh(), ['buyer_signature' => 'anything']);
            $this->fail('Prefilling a signature is signing on somebody else\'s behalf.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_requires_recipient', $e->code());
        }
    }

    public function test_after_the_freeze_only_signer_specific_fields_of_unsigned_recipients_may_change(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        $scenario->signAs($envelope, $buyer);
        $envelope->refresh();
        $this->assertNotNull($envelope->content_frozen_at);

        $seller = $scenario->recipient($envelope, 'seller');

        // Signer-specific, owner has not signed: allowed.
        $scenario->machine()->submitValues($seller, ['seller_title' => 'Director of Synthetic Affairs']);
        $this->assertSame(
            'Director of Synthetic Affairs',
            EnvelopeFieldValue::query()->where('schema_field_id', 'seller_title')->sole()->value,
        );

        // Material, owned by the same unsigned recipient: refused all the same. Free text on
        // an agreement is what the agreement says.
        try {
            $scenario->machine()->submitValues($seller->refresh(), ['seller_notes' => 'A late addition']);
            $this->fail('Material content is frozen after the first acceptance.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_frozen', $e->code());
        }
    }

    /**
     * The other half of the post-freeze rule: signer-specific is not enough on its own, the
     * owner must also still be unsigned. Otherwise a signer could change their own printed
     * name or title after attesting to it.
     */
    public function test_a_signed_recipient_cannot_change_even_their_own_signer_specific_field(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->signAs($envelope, $buyer);

        try {
            $scenario->machine()->submitValues($buyer->refresh(), ['buyer_signature' => 'A different mark']);
            $this->fail('An attested value is not the signer\'s to revise.');
        } catch (RecipientNotEligible $e) {
            $this->assertSame('signed', $e->state);
        }
    }

    public function test_the_sender_is_as_constrained_as_a_signer_once_content_is_frozen(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'));

        try {
            $scenario->machine()->setSenderValues($envelope->refresh(), ['seller_notes' => 'Sender correction']);
            $this->fail('A sender cannot edit material content people have already signed.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_frozen', $e->code());
        }

        // A signer-specific field is still not the sender's to write, but it is not frozen:
        // it is refused for the reason it was always refused.
        $scenario->machine()->setSenderValues($envelope->refresh(), ['seller_title' => 'Prefilled title']);
        $this->assertSame(
            'Prefilled title',
            EnvelopeFieldValue::query()->where('schema_field_id', 'seller_title')->sole()->value,
        );
    }

    public function test_freezing_stamps_material_values_and_leaves_signer_specific_ones_open(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->signAs($envelope, $buyer);

        $values = EnvelopeFieldValue::query()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy('schema_field_id');

        $this->assertTrue($values['buyer_ack']->isFrozen());
        $this->assertTrue($values['agreement_effective_date']->isFrozen());
        $this->assertFalse($values['buyer_signature']->isFrozen());
    }
}
