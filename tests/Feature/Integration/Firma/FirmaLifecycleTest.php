<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FirmaFacadeScenario;
use Tests\TestCase;

/**
 * Create, prefill, patch, send and withdraw a signing request through the facade.
 *
 * Everything asserted here has to be true of the *envelope* as well as of the response body,
 * because the claim under test is that the facade is a projection of one state machine rather
 * than a second implementation of one (AGENTS.md). A response that said "sent" over an
 * envelope still in `draft` would pass a body-only test and be a lie.
 */
class FirmaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/functions/v1/signing-request-api/signing-requests';

    /**
     * A template addressed by this application's own public id.
     */
    public function test_a_draft_is_created_from_a_native_template_id(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();

        $response = $this->postJson(self::BASE, [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic NDA from a native id',
            'recipients' => [
                ['template_user_id' => 'buyer', 'first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test', 'designation' => 'Signer', 'order' => 1],
                ['template_user_id' => 'seller', 'first_name' => 'Sam', 'last_name' => 'Seller', 'email' => 'sam@seller.example.test', 'designation' => 'Signer', 'order' => 2],
            ],
            'fields' => [
                ['variable_name' => 'agreement_date', 'read_only_value' => '2026-02-01'],
            ],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        // Create returns a *string* status. Polling returns an object of booleans; the two
        // are deliberately different representations (docs/HANDOFF.md section 10).
        $response->assertJsonPath('status', 'draft');
        $this->assertIsString($response->json('status'));

        // The create response spells the coordinates correctly. The two upstream typos
        // belong to the read shape only (disagreement D9).
        $field = $response->json('fields.0');
        $this->assertArrayHasKey('x_position', $field);
        $this->assertArrayHasKey('height', $field);
        $this->assertArrayNotHasKey('x_postion', $field);
        $this->assertArrayNotHasKey('heigh', $field);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertSame(EnvelopeState::Draft, $envelope->state);
        $this->assertSame('Synthetic NDA from a native id', $envelope->title);

        // The recipient contacts the request supplied were written into the copied schema
        // before anything was persisted, so the rows and the schema agree from the start.
        $this->assertSame(
            ['dana@buyer.example.test', 'sam@seller.example.test'],
            $envelope->recipients->pluck('email')->all(),
        );
        $this->assertSame(['Dana Buyer', 'Sam Seller'], $envelope->recipients->pluck('name')->all());
    }

    /**
     * The same template addressed by the provider id the consumer already has hardcoded.
     *
     * `docs/HANDOFF.md` section 6 keeps native ids and imported aliases in separate fields,
     * which is what lets both work without either becoming the other.
     */
    public function test_a_draft_is_created_from_an_imported_template_alias(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();
        $scenario->alias($version->template, '11111111-2222-3333-4444-555555555555');

        $response = $this->postJson(self::BASE, [
            'template_id' => '11111111-2222-3333-4444-555555555555',
            'name' => 'Synthetic NDA from an imported alias',
            'recipients' => [
                ['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test'],
                ['order' => 2, 'first_name' => 'Sam', 'email' => 'sam@seller.example.test'],
            ],
            'fields' => [['variable_name' => 'agreement_date', 'read_only_value' => '2026-02-01']],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertSame($version->public_id, $envelope->source_template_version_id);
    }

    /**
     * The `template_id` in a create response is one the facade will accept back.
     *
     * The envelope records the template *version* it copied, which is the right thing to
     * record. But a version id is not something a caller can hand back as `template_id`, so
     * echoing it would give a consumer the one id in the response it would naturally store
     * and then get a `404` for on its next create.
     */
    public function test_the_created_template_id_round_trips(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();

        $body = [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic round trip',
            'recipients' => [
                ['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test'],
                ['order' => 2, 'first_name' => 'Sam', 'email' => 'sam@seller.example.test'],
            ],
        ];

        $first = $this->postJson(self::BASE, $body, FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $this->assertSame($version->template->public_id, $first->json('template_id'));

        // The value the response gave is a value the next create accepts.
        $this->postJson(
            self::BASE,
            array_replace($body, ['template_id' => $first->json('template_id')]),
            FirmaFacadeScenario::headers($issued),
        )->assertStatus(201);
    }

    /**
     * A template *version* id resolves too, so anything already stored keeps working.
     */
    public function test_a_template_version_id_also_resolves(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();

        $this->postJson(self::BASE, [
            'template_id' => $version->public_id,
            'name' => 'Synthetic from a version id',
            'recipients' => [['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test']],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(201)
            ->assertJsonPath('template_id', $version->template->public_id);
    }

    public function test_an_unknown_template_id_is_not_found(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();

        $this->postJson(self::BASE, [
            'template_id' => 'nothing-of-ours',
            'name' => 'Synthetic',
            'recipients' => [['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test']],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    /**
     * An override that matches no field is an error, not a silent no-op.
     *
     * Upstream ignores an unmatched override. The capability matrix rules that out: a dropped
     * prefill is a blank in an executed agreement that nobody notices until a counterparty
     * asks about it.
     */
    public function test_a_prefill_that_matches_no_field_is_refused(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();

        $this->postJson(self::BASE, [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic',
            'recipients' => [['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test']],
            'fields' => [['variable_name' => 'not_a_field_on_this_template', 'final_value' => 'x']],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');

        $this->assertSame(0, Envelope::query()->count());
    }

    /**
     * The consumer's singular `field` patch, addressed by `variable_name`.
     */
    public function test_a_field_is_patched_by_variable_name(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $response = $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => ['variable_name' => 'agreement_date', 'value' => '2026-03-15'],
        ], FirmaFacadeScenario::headers($issued))->assertOk();

        // The response is the field as `/fields` renders it, plus the singular `warning`
        // (disagreement D6 for the shape, D7 for the singular).
        $response->assertJsonPath('variable_name', 'agreement_date');
        $response->assertJsonPath('value', '2026-03-15');
        $response->assertJsonPath('final_value', '2026-03-15');
        $response->assertJsonPath('warning', null);

        $this->assertSame(
            '2026-03-15',
            $this->fields($envelope, $issued)['agreement_effective_date']['value'],
        );
    }

    /** The same field, addressed by its schema id. */
    public function test_a_field_is_patched_by_id(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => ['id' => 'agreement_effective_date', 'final_value' => '2026-04-01'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('id', 'agreement_effective_date')
            ->assertJsonPath('value', '2026-04-01');
    }

    public function test_a_patch_for_an_unresolvable_variable_name_is_refused(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => ['variable_name' => 'no_such_variable', 'value' => 'x'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    /**
     * Two entity types in one PATCH is a 400, as upstream's own description requires.
     */
    public function test_a_patch_cannot_update_two_entity_types(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => ['id' => 'agreement_effective_date', 'value' => 'x'],
            'recipient' => ['id' => $envelope->recipients->first()->public_id, 'email' => 'other@example.test'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    /**
     * The properties form of PATCH is a 501 naming what was asked for.
     *
     * The title is part of the immutable snapshot, which is what lets an executed agreement
     * be proved against what the parties were shown. Accepting the rename and doing nothing
     * would be the successful no-op AGENTS.md forbids.
     */
    public function test_renaming_a_signing_request_is_not_implemented(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'name' => 'A different title',
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.patchable', ['field', 'recipient']);

        $this->assertNotSame('A different title', $envelope->refresh()->title);
    }

    /**
     * `prefilled_editable` describes the immutable schema, so flipping it is a 501.
     */
    public function test_making_a_read_only_field_editable_is_not_implemented(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => [
                'id' => 'agreement_effective_date',
                'value' => '2026-05-01',
                // The field is read-only in the template, so this asks for a change the
                // copied schema cannot express.
                'prefilled_editable' => true,
            ],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.unsupported_option', 'field.prefilled_editable');
    }

    /**
     * A `prefilled_editable` that agrees with the field is the no-op it looks like.
     *
     * The guard refuses a *change*, not a restatement: refusing both would make the facade
     * unusable for a client that echoes the field back.
     */
    public function test_a_prefilled_editable_that_agrees_with_the_field_is_accepted(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => ['id' => 'agreement_effective_date', 'value' => '2026-05-02', 'prefilled_editable' => false],
        ], FirmaFacadeScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('value', '2026-05-02');
    }

    /** A recipient's contact details, corrected before the request goes out. */
    public function test_a_recipient_is_patched(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);
        $recipient = $envelope->recipients->first();

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'recipient' => [
                'id' => $recipient->public_id,
                'first_name' => 'Corrected',
                'last_name' => 'Buyer',
                'email' => 'corrected@buyer.example.test',
            ],
        ], FirmaFacadeScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('id', $recipient->public_id)
            ->assertJsonPath('name', 'Corrected Buyer')
            ->assertJsonPath('email', 'corrected@buyer.example.test');

        $this->assertSame('corrected@buyer.example.test', $recipient->refresh()->email);
        // The identity snapshot is corrected with them, rather than left describing the
        // address the invitation is no longer going to.
        $this->assertSame('corrected@buyer.example.test', $recipient->identity_snapshot['email']);
    }

    /**
     * `/send`, and the union body that resolves disagreement D8.
     */
    public function test_send_emits_both_the_schema_and_the_example_key_sets(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $response = $this->postJson(
            self::BASE.'/'.$envelope->public_id.'/send',
            [],
            FirmaFacadeScenario::headers($issued),
        )->assertOk();

        // The declared schema's members, camelCase.
        foreach (['success', 'message', 'sentTo', 'sentAt'] as $key) {
            $this->assertArrayHasKey($key, $response->json(), "schema member $key");
        }

        // The operation example's members, snake_case. They share only `message`, so
        // satisfying one shape does not break the other.
        foreach (['message', 'signing_request_id', 'recipients_notified', 'sent_date', 'expires_at'] as $key) {
            $this->assertArrayHasKey($key, $response->json(), "example member $key");
        }

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('signing_request_id', $envelope->public_id);

        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
        // Sequential order: only the first stage was invited, so only they are reported.
        $this->assertSame(1, $response->json('recipients_notified'));
    }

    public function test_sending_twice_is_a_conflict(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->postJson(
            self::BASE.'/'.$envelope->public_id.'/send',
            [],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(409)
            ->assertJsonPath('error', 'invalid_state')
            ->assertJsonPath('details.transition', 'send');
    }

    /**
     * Send refuses while a party is not ready, and says which prefill is missing.
     */
    public function test_send_refuses_when_a_required_prefill_is_missing(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplate();

        $created = $this->postJson(self::BASE, [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic without its prefill',
            'recipients' => [
                ['order' => 1, 'first_name' => 'Dana', 'email' => 'dana@buyer.example.test'],
                ['order' => 2, 'first_name' => 'Sam', 'email' => 'sam@seller.example.test'],
            ],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $this->postJson(
            self::BASE.'/'.$created->json('id').'/send',
            [],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'unprocessable_entity');

        // The same readiness projection, reachable without a second guess.
        $users = $this->getJson(
            self::BASE.'/'.$created->json('id').'/users',
            FirmaFacadeScenario::headers($issued),
        )->assertOk();

        $this->assertFalse($users->json('results.0.ready_to_send'));
        $this->assertSame('agreement_date', $users->json('results.0.required_read_only_fields.0.variable_name'));
        $this->assertFalse($users->json('results.0.required_read_only_fields.0.has_value'));
    }

    /** `/cancel`, with the reason recorded on the envelope. */
    public function test_a_sent_request_is_cancelled_with_a_reason(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->postJson(
            self::BASE.'/'.$envelope->public_id.'/cancel',
            ['reason' => 'Counterparty withdrew.'],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertOk()
            ->assertJsonPath('signing_request_id', $envelope->public_id)
            ->assertJsonPath('notify_signers', true)
            ->assertJsonStructure(['message', 'signing_request_id', 'cancelled_on', 'notify_signers', 'emails_sent']);

        $envelope->refresh();
        $this->assertSame(EnvelopeState::Cancelled, $envelope->state);
        $this->assertSame('Counterparty withdrew.', $envelope->cancel_reason);
    }

    /**
     * Cancelling a draft succeeds here, where upstream answers 409.
     *
     * Recorded in the capability matrix as an intentional difference: withdrawing a draft is
     * a legal transition in this state machine, and reproducing somebody else's limitation
     * would leave the caller holding a draft they asked us to withdraw.
     */
    public function test_cancelling_a_draft_succeeds(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->postJson(
            self::BASE.'/'.$envelope->public_id.'/cancel',
            [],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertOk()
            // Nobody had been written to, so the withdrawal notice goes to nobody.
            ->assertJsonPath('emails_sent', 0);

        $this->assertSame(EnvelopeState::Cancelled, $envelope->refresh()->state);
    }

    public function test_cancelling_twice_is_a_conflict(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->cancelled();

        $this->postJson(
            self::BASE.'/'.$envelope->public_id.'/cancel',
            [],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(409)
            ->assertJsonPath('error', 'invalid_state');
    }

    /**
     * Suppressing the withdrawal notice is a 501, not an accepted flag.
     */
    public function test_suppressing_the_cancellation_notice_is_not_implemented(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->postJson(
            self::BASE.'/'.$envelope->public_id.'/cancel',
            ['notify_signers' => false],
            FirmaFacadeScenario::headers($issued),
        )
            ->assertStatus(501)
            ->assertJsonPath('error', 'unsupported')
            ->assertJsonPath('details.unsupported_option', 'notify_signers');

        // Refused, and nothing happened: fail closed rather than half-cancel.
        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
    }

    /** A patch after send is refused by the state machine, not by the facade. */
    public function test_a_sent_request_cannot_be_patched(): void
    {
        $scenario = FirmaFacadeScenario::create();
        $issued = $scenario->credential();
        $envelope = $scenario->sent();

        $this->patchJson(self::BASE.'/'.$envelope->public_id, [
            'field' => ['id' => 'agreement_effective_date', 'value' => '2026-06-01'],
        ], FirmaFacadeScenario::headers($issued))
            ->assertStatus(409)
            ->assertJsonPath('error', 'invalid_state')
            ->assertJsonPath('details.transition', 'update');
    }

    /* ------------------------------------------------------------------- helpers */

    private function draft(FirmaFacadeScenario $scenario, object $issued): Envelope
    {
        $version = $scenario->publishedTemplate();

        $created = $this->postJson(self::BASE, [
            'template_id' => $version->template->public_id,
            'name' => 'Synthetic draft',
            'recipients' => [
                ['order' => 1, 'first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test'],
                ['order' => 2, 'first_name' => 'Sam', 'last_name' => 'Seller', 'email' => 'sam@seller.example.test'],
            ],
            'fields' => [['variable_name' => 'agreement_date', 'read_only_value' => '2026-02-01']],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        return Envelope::query()->where('public_id', $created->json('id'))->firstOrFail();
    }

    /**
     * @return array<string, array<string, mixed>> Field id => row.
     */
    private function fields(Envelope $envelope, object $issued): array
    {
        $rows = $this->getJson(
            self::BASE.'/'.$envelope->public_id.'/fields',
            FirmaFacadeScenario::headers($issued),
        )->assertOk()->json('results');

        $byId = [];

        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        return $byId;
    }
}
