<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Firma;

use App\Domain\Delivery\Events\SigningRequestPayload;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Integration\Firma\FirmaProfile;
use App\Domain\Preparation\Documents\Models\Document;
use App\Domain\Preparation\Schema\RecipientDesignation;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Signing\EnvelopeDesignationTest;
use Tests\Support\FirmaFacadeScenario;
use Tests\Support\PdfFixtures;
use Tests\Support\SigningFixtures;
use Tests\TestCase;

/**
 * Signer, Approver and CC through the Firma facade (issue #169, part 3).
 *
 * Upstream's three designations map one-to-one onto the field schema's, and each one has to
 * mean the same thing at every step a consumer can see: what they create, what they read back,
 * what is sent, what completes, and what the evidence records. The headline test walks one
 * request through all of those; the rest pin the rules at the edges.
 */
class FirmaDesignationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/functions/v1/signing-request-api/signing-requests';

    public function test_a_signer_an_approver_and_two_cc_recipients_keep_their_designations_from_create_to_evidence(): void
    {
        [$scenario, $issued] = $this->scenario();

        $created = $this->postJson(self::BASE.'/create-and-send', self::documentRequest(), FirmaFacadeScenario::headers($issued))
            ->assertStatus(201);

        // Created: the designation each party was given, and a cc's place in the cc sequence.
        $byEmail = collect($created->json('recipients'))->keyBy('email');
        $this->assertSame(['Signer', 1], [$byEmail['dana@buyer.example.test']['designation'], $byEmail['dana@buyer.example.test']['order']]);
        $this->assertSame(['Approver', 2], [$byEmail['morgan@approver.example.test']['designation'], $byEmail['morgan@approver.example.test']['order']]);
        $this->assertSame(['CC', 1], [$byEmail['records@cc.example.test']['designation'], $byEmail['records@cc.example.test']['order']]);
        $this->assertSame(['CC', 2], [$byEmail['legal@cc.example.test']['designation'], $byEmail['legal@cc.example.test']['order']]);

        $envelope = Envelope::query()->where('public_id', $created->json('id'))->firstOrFail();
        $this->assertSame('1.2', $envelope->fieldSchema()->schemaVersion->toString());
        $this->assertSame([['r1'], ['r2']], $envelope->fieldSchema()->signingOrder);

        // Sent: the signer is released, the approver waits their turn, the cc recipients are copied.
        $states = $envelope->recipients()->get()->mapWithKeys(fn ($r) => [$r->email => $r->state])->all();
        $this->assertSame(RecipientState::Active, $states['dana@buyer.example.test']);
        $this->assertSame(RecipientState::Pending, $states['morgan@approver.example.test']);
        $this->assertSame(RecipientState::Copied, $states['records@cc.example.test']);
        $this->assertSame(RecipientState::Copied, $states['legal@cc.example.test']);

        // Retrieved: /users says the same thing.
        $users = collect($this->getJson(self::BASE.'/'.$envelope->public_id.'/users', FirmaFacadeScenario::headers($issued))
            ->assertOk()->json('results'))->keyBy('email');
        $this->assertSame('Approver', $users['morgan@approver.example.test']['designation']);
        $this->assertSame('CC', $users['legal@cc.example.test']['designation']);
        $this->assertSame(2, $users['legal@cc.example.test']['order']);

        // Completed: the signer signs, the approver approves, nobody waits for a cc recipient.
        $signing = $scenario->signing;
        $signing->signAs($envelope->refresh(), $signing->recipient($envelope, 'r1'), 'session-signer');
        $signing->signAs($envelope->refresh(), $signing->recipient($envelope, 'r2'), 'session-approver');
        $this->assertSame(EnvelopeState::Finalizing, $envelope->refresh()->state);
        $scenario->finalize($envelope);

        // The webhook payload a consumer is sent.
        $payload = collect(SigningRequestPayload::for($envelope->refresh())['recipients'])->keyBy('email');
        $this->assertSame('Approver', $payload['morgan@approver.example.test']['designation']);
        $this->assertSame(['CC', 1], [$payload['records@cc.example.test']['designation'], $payload['records@cc.example.test']['order']]);

        // The evidence: two acceptances, in their roles; no cc recipient attested anything.
        $evidence = json_decode((string) Storage::disk('documents')->get(
            Artifact::query()->where('envelope_id', $envelope->getKey())->where('kind', ArtifactKind::EvidenceJson->value)->firstOrFail()->path,
        ), true);
        $this->assertSame(['signer', 'approver'], array_column($evidence['attestation_chain'], 'designation'));
    }

    public function test_the_webhook_payload_and_the_facade_spell_designations_the_same_way(): void
    {
        $this->assertSame(FirmaProfile::DESIGNATIONS, SigningRequestPayload::DESIGNATIONS);
        $this->assertSame(RecipientDesignation::values(), array_keys(FirmaProfile::DESIGNATIONS));
    }

    public function test_a_request_with_no_signer_is_refused_before_anything_is_stored(): void
    {
        [, $issued] = $this->scenario();
        $body = self::documentRequest();
        $body['recipients'][0]['designation'] = 'Approver';

        $this->postJson(self::BASE.'/create-and-send', $body, FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');

        $this->assertSame(0, Document::query()->whereNull('uploaded_by')->count());
    }

    public function test_a_field_assigned_to_a_cc_recipient_is_refused(): void
    {
        [, $issued] = $this->scenario();
        $body = self::documentRequest();
        $body['fields'][] = ['type' => 'text', 'page_number' => 1, 'recipient_id' => 'temp_3', 'position' => ['x' => 60.0, 'y' => 10.0, 'width' => 20.0, 'height' => 3.0]];

        $this->postJson(self::BASE.'/create-and-send', $body, FirmaFacadeScenario::headers($issued))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_request');
    }

    public function test_a_cc_recipients_order_never_answers_for_a_signing_position(): void
    {
        [, $issued] = $this->scenario();
        $body = self::documentRequest();
        // "order 1" for this field must mean the first signer, not the first cc recipient.
        $body['fields'][0] = ['type' => 'signature', 'page_number' => 1, 'order' => 1, 'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0]];

        $created = $this->postJson(self::BASE.'/create-and-send', $body, FirmaFacadeScenario::headers($issued))->assertStatus(201);
        $envelope = Envelope::query()->where('public_id', $created->json('id'))->firstOrFail();

        $this->assertSame('r1', $envelope->fieldSchema()->fields[0]->recipientId);
    }

    public function test_a_signer_only_request_keeps_schema_1_0(): void
    {
        [, $issued] = $this->scenario();
        $body = self::documentRequest();
        $body['recipients'] = [$body['recipients'][0]];
        $body['fields'] = [$body['fields'][0]];

        $created = $this->postJson(self::BASE.'/create-and-send', $body, FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $this->assertSame('1.0', Envelope::query()->where('public_id', $created->json('id'))->firstOrFail()->fieldSchema()->schemaVersion->toString());
    }

    public function test_a_template_request_adds_its_cc_recipients_and_a_matching_template_cc_is_not_duplicated(): void
    {
        [$scenario, $issued] = $this->scenario();
        $schema = EnvelopeDesignationTest::schemaWithApproverAndCc();
        $version = $scenario->publishedTemplate($schema, 'Synthetic approval template');

        $created = $this->postJson(self::BASE, [
            'template_id' => $version->template->public_id,
            'recipients' => [
                // Never matched to a template party, whatever its order says.
                ['first_name' => 'Records', 'last_name' => 'Desk', 'email' => 'RECORDS@example.test', 'designation' => 'CC', 'order' => 1],
                ['first_name' => 'Legal', 'email' => 'legal@cc.example.test', 'designation' => 'CC', 'order' => 2],
            ],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $created->json('id'))->firstOrFail();
        $copied = $envelope->recipients()->where('designation', 'cc')->orderBy('id')->get();

        // The template's cc recipient, named by the request that used its address, and the new one.
        $this->assertSame(['records@example.test', 'legal@cc.example.test'], $copied->pluck('email')->all());
        $this->assertSame('Records Desk', $copied[0]->name);
        $this->assertSame(['copied', 'copied'], $copied->map(fn ($r) => $r->state->value)->all());
        // The template's signer and approver are untouched by the cc entries.
        $this->assertSame('signer@example.test', $envelope->recipients()->where('schema_recipient_id', 'signer')->value('email'));
        $this->assertSame('manager@example.test', $envelope->recipients()->where('schema_recipient_id', 'manager')->value('email'));
    }

    /**
     * Upgrade fixture: a template published before schema 1.2 gains a cc recipient. Its copy
     * for this envelope is lifted to 1.2; the template's own document is unchanged.
     */
    public function test_a_cc_added_to_a_template_published_before_1_2_lifts_only_the_envelopes_copy(): void
    {
        [$scenario, $issued] = $this->scenario();
        $version = $scenario->publishedTemplate(SigningFixtures::singleSigner(), 'Synthetic 1.0 template');
        $this->assertSame('1.0', $version->field_schema['schema_version']);

        $created = $this->postJson(self::BASE, [
            'template_id' => $version->template->public_id,
            'recipients' => [['first_name' => 'Records', 'email' => 'records@cc.example.test', 'designation' => 'CC', 'order' => 1]],
        ], FirmaFacadeScenario::headers($issued))->assertStatus(201);

        $envelope = Envelope::query()->where('public_id', $created->json('id'))->firstOrFail();
        $this->assertSame('1.2', $envelope->fieldSchema()->schemaVersion->toString());
        $this->assertSame('1.0', $version->refresh()->field_schema['schema_version']);

        // And a template request that adds no cc keeps the template's version and digest.
        $plain = $this->postJson(self::BASE, ['template_id' => $version->template->public_id], FirmaFacadeScenario::headers($issued))->assertStatus(201);
        $plainEnvelope = Envelope::query()->where('public_id', $plain->json('id'))->firstOrFail();
        $this->assertSame($version->field_schema_sha256, $plainEnvelope->field_schema_sha256);
    }

    /** @return array<string, mixed> */
    private static function documentRequest(): array
    {
        return [
            'name' => 'Synthetic approval agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'recipients' => [
                ['id' => 'temp_1', 'first_name' => 'Dana', 'last_name' => 'Buyer', 'email' => 'dana@buyer.example.test', 'designation' => 'Signer', 'order' => 1],
                ['id' => 'temp_2', 'first_name' => 'Morgan', 'email' => 'morgan@approver.example.test', 'designation' => 'Approver', 'order' => 2],
                // Given out of order on purpose: the cc sequence is the `order` they carry.
                ['id' => 'temp_4', 'first_name' => 'Legal', 'email' => 'legal@cc.example.test', 'designation' => 'CC', 'order' => 2],
                ['id' => 'temp_3', 'first_name' => 'Records', 'email' => 'records@cc.example.test', 'designation' => 'CC', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'recipient_id' => 'temp_1', 'position' => ['x' => 10.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0]],
                ['type' => 'approval_checkmark', 'page_number' => 1, 'recipient_id' => 'temp_2', 'position' => ['x' => 60.0, 'y' => 70.0, 'width' => 3.0, 'height' => 2.0]],
                ['type' => 'approval_signature', 'page_number' => 1, 'recipient_id' => 'temp_2', 'position' => ['x' => 60.0, 'y' => 80.0, 'width' => 25.0, 'height' => 4.0]],
                ['type' => 'approval_date', 'page_number' => 1, 'recipient_id' => 'temp_2', 'position' => ['x' => 60.0, 'y' => 86.0, 'width' => 25.0, 'height' => 2.0], 'read_only' => true],
            ],
        ];
    }

    /**
     * @return array{0: FirmaFacadeScenario, 1: IssuedServiceCredential}
     */
    private function scenario(): array
    {
        $scenario = FirmaFacadeScenario::create();

        return [$scenario, $scenario->credential()];
    }
}
