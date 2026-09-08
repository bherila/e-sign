<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Exceptions\RecipientNotEligible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Ordering, stage advancement, and the fact that the last signature reaches `finalizing` and
 * not `completed`.
 */
class EnvelopeSigningOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_later_signer_cannot_act_before_their_stage_opens(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $seller = $scenario->recipient($envelope, 'seller');

        try {
            $scenario->machine()->accept($seller, $scenario->acceptanceRequest($envelope));
            $this->fail('Sequential order is a server-side guard, not a property of which link works.');
        } catch (RecipientNotEligible $e) {
            $this->assertSame('recipient_not_eligible', $e->code());
            $this->assertSame('pending', $e->state);
        }

        $this->assertSame(0, $envelope->attestations()->count());
    }

    public function test_signing_the_first_stage_activates_the_second(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        $result = $scenario->signAs($envelope, $buyer);

        $this->assertFalse($result->completedSigning());
        $this->assertSame(EnvelopeState::InProgress, $result->to);
        $this->assertSame([EnvelopeEvent::RecipientSigned->value], $result->eventNames());

        $envelope->refresh();
        $this->assertSame(RecipientState::Signed, $scenario->recipient($envelope, 'buyer')->state);
        $this->assertSame(RecipientState::Active, $scenario->recipient($envelope, 'seller')->state);
        $this->assertNotNull($scenario->recipient($envelope, 'buyer')->signed_at);
    }

    public function test_the_last_signature_reaches_finalizing_and_never_completed(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();

        $scenario->signAs($envelope, $scenario->recipient($envelope, 'buyer'), 'session-buyer');
        $result = $scenario->signAs(
            $envelope->refresh(),
            $scenario->recipient($envelope, 'seller'),
            'session-seller',
        );

        $this->assertTrue($result->completedSigning());
        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
        $this->assertNull($envelope->completed_at);
        $this->assertNull($envelope->artifact_ref);

        // Completion is the finalizer's assertion, so no completion event yet.
        $this->assertNotContains(EnvelopeEvent::Completed->value, $scenario->sink->names());
    }

    public function test_a_single_signer_envelope_finalizes_on_the_only_signature(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent(['field_schema' => SigningFixtures::singleSigner()]);

        $result = $scenario->signAs($envelope, $scenario->recipient($envelope, 'signer'));

        $this->assertSame(EnvelopeState::Finalizing, $result->to);
    }

    public function test_parallel_recipients_are_all_eligible_at_once_and_may_sign_in_any_order(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent([
            'field_schema' => SigningFixtures::parallelTwoSigners(),
            'signing_mode' => 'parallel',
        ]);

        $this->assertSame(
            [RecipientState::Active, RecipientState::Active],
            $envelope->recipients()->get()->pluck('state')->all(),
        );

        // Bob first, which sequential mode would have refused.
        $first = $scenario->signAs($envelope, $scenario->recipient($envelope, 'bob'), 'session-bob');
        $this->assertSame(EnvelopeState::InProgress, $first->to);

        $second = $scenario->signAs(
            $envelope->refresh(),
            $scenario->recipient($envelope, 'alice'),
            'session-alice',
        );
        $this->assertSame(EnvelopeState::Finalizing, $second->to);
    }

    public function test_a_signed_recipient_cannot_sign_again_in_a_new_session(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');
        $scenario->signAs($envelope, $buyer, 'session-one');

        $this->expectException(RecipientNotEligible::class);

        $scenario->machine()->accept(
            $buyer->refresh(),
            $scenario->acceptanceRequest($envelope, 'session-two'),
        );
    }

    public function test_a_decline_ends_the_envelope_for_everyone(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();
        $buyer = $scenario->recipient($envelope, 'buyer');

        $result = $scenario->machine()->decline($buyer, 'The terms are not acceptable.');

        $this->assertSame(EnvelopeState::Declined, $result->to);
        $this->assertSame([
            EnvelopeEvent::RecipientDeclined->value,
            EnvelopeEvent::Declined->value,
        ], $result->eventNames());

        $envelope->refresh();
        $this->assertNotNull($envelope->declined_at);
        $this->assertSame(RecipientState::Declined, $scenario->recipient($envelope, 'buyer')->state);
        $this->assertSame(
            'The terms are not acceptable.',
            $scenario->recipient($envelope, 'buyer')->decline_reason,
        );
        // The seller is never asked to execute an agreement a party has rejected.
        $this->assertSame(RecipientState::Pending, $scenario->recipient($envelope, 'seller')->state);
    }

    public function test_a_pending_recipient_cannot_decline(): void
    {
        $scenario = SigningScenario::create();
        $envelope = $scenario->sent();

        $this->expectException(RecipientNotEligible::class);

        $scenario->machine()->decline($scenario->recipient($envelope, 'seller'), 'Not my turn.');
    }
}
