<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\ValueSource;
use App\Domain\Signing\Exceptions\FieldSubmissionRejected;
use App\Domain\Signing\Exceptions\IllegalTransition;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use App\Domain\Signing\Fields\CanonicalValue;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
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

    /**
     * A submission that wrote nothing is not a transition. Bumping the version for it would
     * invalidate every other recipient's review for no reason at all.
     */
    public function test_an_empty_submission_changes_nothing(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $before = $envelope->version;

        $result = $scenario->machine()->submitValues($scenario->recipient($envelope, 'buyer'), []);

        $this->assertSame([], $result->fieldIds);
        $this->assertSame($before, $result->envelopeVersion);
        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
        $this->assertSame($before, $envelope->version);
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

    /**
     * The sender is bound by the same rule, and it is not the freeze that binds them.
     *
     * A signer-specific field is deliberately outside the material digest — that is what
     * makes it signer-specific — so a signed recipient's attestation cannot detect a change
     * to it. If the sender could still write one, they could rewrite a signed party's
     * printed name or title while a later signer was outstanding, and #28 would render a
     * value nobody agreed to.
     */
    public function test_a_sender_cannot_rewrite_the_fields_of_a_recipient_who_has_signed(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent([
            'field_schema' => SigningFixtures::parallelTwoSigners(),
            'signing_mode' => 'parallel',
        ]);
        $scenario->machine()->setSenderValues($envelope, ['alice_title' => 'Head of Synthetic Affairs']);
        $scenario->signAs($envelope->refresh(), $scenario->recipient($envelope, 'alice'), 'session-alice');

        // Bob is still outstanding, so the envelope is in_progress and sender edits are
        // still a legal transition. Alice's fields are not.
        $this->assertSame(EnvelopeState::InProgress, $envelope->refresh()->state);

        try {
            $scenario->machine()->setSenderValues($envelope, ['alice_title' => 'Something she never saw']);
            $this->fail('An attested recipient\'s own fields are as closed as the agreement text.');
        } catch (FieldSubmissionRejected $e) {
            $this->assertSame('field_owner_signed', $e->code());
            $this->assertSame('alice_title', $e->fieldId);
        }

        $this->assertSame(
            'Head of Synthetic Affairs',
            EnvelopeFieldValue::query()->where('schema_field_id', 'alice_title')->sole()->value,
        );

        // Bob has not signed, so his are still open to a prefill.
        $scenario->machine()->setSenderValues($envelope->refresh(), ['bob_company' => 'Synthetic Holdings']);
        $this->assertSame(
            'Synthetic Holdings',
            EnvelopeFieldValue::query()->where('schema_field_id', 'bob_company')->sole()->value,
        );
    }

    /**
     * A declined owner is stopped a level higher up, and that is worth pinning.
     *
     * Declining ends the envelope for everyone, so `set_sender_values` is not a legal
     * transition from `declined` at all — the per-field owner check never runs. It still
     * treats a declined owner as having attested, because the ordering of those two guards
     * is not something the field rule should depend on.
     */
    public function test_a_decline_closes_the_envelope_before_the_field_rules_are_reached(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent([
            'field_schema' => SigningFixtures::parallelTwoSigners(),
            'signing_mode' => 'parallel',
        ]);
        $scenario->machine()->decline($scenario->recipient($envelope, 'alice'), 'No.');

        $this->assertTrue($scenario->recipient($envelope->refresh(), 'alice')->state->hasAttested());

        $this->expectException(IllegalTransition::class);
        $this->expectExceptionMessageMatches('/state "declined"/');

        $scenario->machine()->setSenderValues($envelope, ['bob_company' => 'Synthetic Holdings']);
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
