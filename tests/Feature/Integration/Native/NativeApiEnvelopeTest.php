<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NativeApiScenario;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * The envelope lifecycle over HTTP: create, read, correct, send, cancel.
 *
 * The transitions themselves are the state machine's, and are tested exhaustively in
 * tests/Feature/Signing. What is asserted here is the translation — that each call reaches
 * the same domain service, that the statuses are the ones the contract promises, and that a
 * refusal arrives as a typed error rather than a 500.
 */
class NativeApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_envelope_is_created_from_a_published_template_version(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $response = $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'title' => 'NDA — Synthetic Counterparty Ltd',
            'expires_in_hours' => 72,
            'recipients' => [
                ['id' => 'buyer', 'name' => 'Dana Buyer', 'email' => 'dana@buyer.test'],
                ['id' => 'seller', 'name' => 'Sam Seller', 'email' => 'sam@seller.test'],
            ],
            'values' => ['agreement_effective_date' => '2026-02-01'],
        ], NativeApiScenario::headers($issued));

        $response->assertCreated()
            ->assertJsonPath('state', 'draft')
            ->assertJsonPath('title', 'NDA — Synthetic Counterparty Ltd')
            ->assertJsonPath('signing_mode', 'sequential')
            ->assertJsonPath('assurance_level', 'pades-b-b')
            ->assertJsonPath('expires_in_hours', 72)
            ->assertJsonPath('source_template_version_id', $version->public_id)
            ->assertJsonPath('document_sha256', $scenario->signing->revision->sha256)
            ->assertJsonPath('recipients.0.schema_recipient_id', 'buyer')
            ->assertJsonPath('recipients.0.name', 'Dana Buyer')
            ->assertJsonPath('recipients.0.email', 'dana@buyer.test')
            ->assertJsonPath('recipients.0.state', 'pending')
            ->assertJsonPath('recipients.1.schema_recipient_id', 'seller')
            ->assertJsonPath('recipients.1.email', 'sam@seller.test');

        // The contacts were written into the copied schema before anything was persisted,
        // so the envelope, its recipients, and their identity snapshots agree.
        $envelope = Envelope::query()->where('public_id', $response->json('id'))->firstOrFail();
        $this->assertSame('dana@buyer.test', $envelope->fieldSchema()->recipient('buyer')?->email);
        $this->assertSame(
            'dana@buyer.test',
            $envelope->recipients()->where('schema_recipient_id', 'buyer')->firstOrFail()->identity_snapshot['email'],
        );

        // The prefill went through the state machine as the sender.
        $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/values', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data.2.field_id', 'agreement_effective_date')
            ->assertJsonPath('data.2.value', '2026-02-01')
            ->assertJsonPath('data.2.source', 'sender');
    }

    public function test_an_envelope_is_created_from_a_document_and_a_field_schema(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $response = $this->postJson('/api/v1/envelopes', [
            'document_id' => $scenario->signing->document->public_id,
            'field_schema' => SigningFixtures::singleSigner(),
            'title' => 'One-signer synthetic agreement',
            'recipients' => [['id' => 'signer', 'name' => 'Sol Signer', 'email' => 'sol@signer.test']],
        ], NativeApiScenario::headers($issued));

        $response->assertCreated()
            ->assertJsonPath('state', 'draft')
            ->assertJsonPath('source_template_version_id', null)
            ->assertJsonPath('recipients.0.email', 'sol@signer.test')
            // The seven-day default, because the key was omitted entirely.
            ->assertJsonPath('expires_in_hours', 168);
    }

    public function test_an_explicit_null_expiry_means_the_envelope_never_expires(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $this->postJson('/api/v1/envelopes', [
            'document_id' => $scenario->signing->document->public_id,
            'field_schema' => SigningFixtures::singleSigner(),
            'expires_in_hours' => null,
        ], NativeApiScenario::headers($issued))
            ->assertCreated()
            ->assertJsonPath('expires_in_hours', null);
    }

    public function test_creating_from_both_a_template_and_a_document_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'document_id' => $scenario->signing->document->public_id,
        ], NativeApiScenario::headers($issued))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields']]]);
    }

    public function test_creating_from_neither_a_template_nor_a_document_is_refused(): void
    {
        $scenario = NativeApiScenario::create();

        $this->postJson('/api/v1/envelopes', ['title' => 'nothing to sign'],
            NativeApiScenario::headers($scenario->credential()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_a_recipient_id_the_schema_does_not_declare_is_refused_rather_than_ignored(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $version = $scenario->publishedTemplateVersion();

        $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'recipients' => [['id' => 'witness', 'email' => 'witness@example.test']],
        ], NativeApiScenario::headers($issued))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_recipient')
            ->assertJsonPath('error.details.unknown_recipient_ids', ['witness'])
            ->assertJsonPath('error.details.declared_recipient_ids', ['buyer', 'seller']);
    }

    public function test_an_unrecognised_assurance_level_is_an_error_and_never_a_downgrade(): void
    {
        $scenario = NativeApiScenario::create();
        $version = $scenario->publishedTemplateVersion();

        $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'assurance_level' => 'pades-b-lta',
        ], NativeApiScenario::headers($scenario->credential()))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, Envelope::query()->count());
    }

    public function test_a_draft_accepts_prefills_and_contact_corrections(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson('/api/v1/envelopes/'.$envelope, [
            'values' => ['agreement_effective_date' => '2026-03-09'],
            'recipients' => [['id' => 'seller', 'email' => 'corrected@seller.test']],
        ], NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('state', 'draft')
            ->assertJsonPath('recipients.1.email', 'corrected@seller.test')
            // The name was not supplied, so it was left alone rather than blanked.
            ->assertJsonPath('recipients.1.name', 'Example Seller');

        $this->getJson('/api/v1/envelopes/'.$envelope.'/values', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data.2.value', '2026-03-09');
    }

    public function test_a_sender_may_not_prefill_a_signature(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $this->patchJson('/api/v1/envelopes/'.$envelope, [
            'values' => ['buyer_signature' => 'data:image/png;base64,AAAA'],
        ], NativeApiScenario::headers($issued))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'field_rejected')
            ->assertJsonPath('error.details.field', 'buyer_signature');
    }

    public function test_a_sent_envelope_cannot_be_patched(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->sentEnvelope($scenario, $issued);

        $this->patchJson('/api/v1/envelopes/'.$envelope, [
            'recipients' => [['id' => 'seller', 'email' => 'too-late@seller.test']],
        ], NativeApiScenario::headers($issued))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'illegal_transition')
            ->assertJsonPath('error.details.from', 'sent');
    }

    public function test_sending_releases_the_first_stage(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued, prefilled: true);

        $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('state', 'sent')
            ->assertJsonPath('recipients.0.state', 'active')
            ->assertJsonPath('recipients.1.state', 'pending');

        $this->getJson('/api/v1/envelopes/'.$envelope.'/recipients', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('data.0.schema_recipient_id', 'buyer')
            ->assertJsonPath('data.0.order_index', 1)
            ->assertJsonPath('data.1.order_index', 2)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_sending_twice_is_a_conflict(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->sentEnvelope($scenario, $issued);

        $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], NativeApiScenario::headers($issued))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'illegal_transition')
            ->assertJsonPath('error.details.transition', 'send')
            ->assertJsonPath('error.details.from', 'sent');
    }

    public function test_sending_an_unprepared_envelope_reports_every_problem_at_once(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->draft($scenario, $issued);

        $response = $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], NativeApiScenario::headers($issued));

        $response->assertStatus(422)->assertJsonPath('error.code', 'send_preconditions_failed');

        $this->assertNotEmpty($response->json('error.details.problems'));
    }

    public function test_an_envelope_is_cancelled_with_a_reason(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->sentEnvelope($scenario, $issued);

        $this->postJson('/api/v1/envelopes/'.$envelope.'/cancel',
            ['reason' => 'The counterparty withdrew.'],
            NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('state', 'cancelled')
            ->assertJsonPath('cancel_reason', 'The counterparty withdrew.');
    }

    public function test_cancelling_a_completed_envelope_is_a_conflict(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->completedEnvelope($scenario);

        $this->postJson('/api/v1/envelopes/'.$envelope->public_id.'/cancel', [],
            NativeApiScenario::headers($issued))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'illegal_transition')
            ->assertJsonPath('error.details.from', 'completed');
    }

    public function test_a_cancel_reason_that_would_be_truncated_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->sentEnvelope($scenario, $issued);

        $this->postJson('/api/v1/envelopes/'.$envelope.'/cancel',
            ['reason' => str_repeat('x', 501)],
            NativeApiScenario::headers($issued))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_an_envelope_id_that_does_not_exist_is_a_404_in_the_native_shape(): void
    {
        $scenario = NativeApiScenario::create();

        $this->getJson('/api/v1/envelopes/01JC0000000000000000000001',
            NativeApiScenario::headers($scenario->credential()))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertExactJson(['error' => [
                'code' => 'not_found',
                'message' => 'No envelope with that id exists in this workspace.',
            ]]);
    }

    /**
     * @return string The envelope's public id.
     */
    private function draft(NativeApiScenario $scenario, $issued, bool $prefilled = false): string
    {
        $version = $scenario->publishedTemplateVersion();

        $response = $this->postJson('/api/v1/envelopes', array_filter([
            'template_version_id' => $version->public_id,
            'values' => $prefilled ? ['agreement_effective_date' => '2026-02-01'] : null,
        ]), NativeApiScenario::headers($issued))->assertCreated();

        return (string) $response->json('id');
    }

    private function sentEnvelope(NativeApiScenario $scenario, $issued): string
    {
        $envelope = $this->draft($scenario, $issued, prefilled: true);

        $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], NativeApiScenario::headers($issued))
            ->assertOk();

        return $envelope;
    }

    /** Signed through to completion with the state machine, which is the only thing that may. */
    private function completedEnvelope(NativeApiScenario $scenario): Envelope
    {
        $signing = $scenario->signing;
        $envelope = $signing->sent();

        foreach (['buyer', 'seller'] as $index => $recipientId) {
            $signing->signAs($envelope->refresh(), $signing->recipient($envelope, $recipientId), 'session-'.$index);
        }

        $envelope->refresh();
        $this->assertSame(EnvelopeState::Finalizing, $envelope->state);

        $signing->machine()->markCompleted($envelope, 'artifacts/synthetic-sealed.pdf');

        return $envelope->refresh();
    }
}
