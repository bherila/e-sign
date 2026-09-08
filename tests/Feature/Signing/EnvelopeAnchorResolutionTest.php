<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Preparation\Anchoring\AnchorResolutionOutcome;
use App\Domain\Preparation\Schema\AnchorPlacementMode;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Preparation\Schema\ValidationCode;
use App\Domain\Signing\Contracts\AnchorResolution;
use App\Domain\Signing\Envelopes\AuditEnvelopeEventSink;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Exceptions\SendPreconditionsFailed;
use App\Domain\Signing\Models\Envelope;
use App\Domain\Signing\Models\EnvelopeFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixtures;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Send-time anchor resolution (issue #23).
 *
 * Send is the authoritative resolution, because an envelope does not have to come from a
 * template: the native API and the Firma facade both build one straight from a document, and
 * neither has a publish step to have caught anything at. What this file pins is that by the
 * time anyone is invited the envelope holds concrete rectangles, that an anchor which cannot be
 * placed stops the send with a message naming the field, and that after the send nothing looks
 * at the document's text again.
 *
 * The document behind the revision is the committed `nda-two-signers` fixture, so the anchors
 * resolve against real positioned text rather than a stub.
 */
class EnvelopeAnchorResolutionTest extends TestCase
{
    use RefreshDatabase;

    private SigningScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->scenario = SigningScenario::create(revisionBytes: PdfFixtures::bytes('nda-two-signers'))->bind();
    }

    // ------------------------------------------------------------------------ resolution

    public function test_sending_stores_the_resolved_rectangle_in_the_envelopes_own_schema(): void
    {
        $envelope = $this->scenario->preparedDraft(['field_schema' => $this->anchoredSchema()]);
        $before = $envelope->field_schema_sha256;

        $this->scenario->machine()->send($envelope);

        $field = $envelope->refresh()->fieldSchema()->field('seller_signature');

        $this->assertNotNull($field);
        // "Counterparty signature:" sits at native (330, 622.4) on page 2 of the fixture and is
        // 12 pt tall; measured from its bottom-left corner, 12.5 pt down.
        $this->assertSame(['x' => 330, 'y' => 646.9, 'width' => 170, 'height' => 36], $field->rect->toArray());

        $receipt = $field->anchor?->resolved;
        $this->assertNotNull($receipt, 'The anchor is kept as provenance beside the rectangle it produced.');
        $this->assertSame($this->scenario->revision->sha256, $receipt->documentSha256);
        $this->assertSame(2, $receipt->page);
        $this->assertSame(1, $receipt->occurrenceIndex);
        $this->assertSame(['x' => 330, 'y' => 622.4, 'width' => 165.6, 'height' => 12], $receipt->anchorRect->toArray());

        // The digest moves with the schema, and stays the digest of the canonical form of it.
        $this->assertNotSame($before, $envelope->field_schema_sha256);
        $this->assertSame(
            hash('sha256', FieldSchemaDocument::fromArray($envelope->field_schema)->canonicalJson()),
            $envelope->field_schema_sha256,
        );
    }

    public function test_a_hand_placed_field_is_untouched_by_a_send_that_resolves_another_one(): void
    {
        $envelope = $this->scenario->preparedDraft(['field_schema' => $this->anchoredSchema()]);
        $before = $envelope->fieldSchema()->field('buyer_signature')?->toArray();

        $this->scenario->machine()->send($envelope);

        $this->assertSame($before, $envelope->refresh()->fieldSchema()->field('buyer_signature')?->toArray());
    }

    public function test_an_envelope_with_no_anchors_keeps_its_schema_and_its_digest(): void
    {
        $envelope = $this->scenario->preparedDraft();
        $before = [$envelope->fieldSchema()->canonicalJson(), $envelope->field_schema_sha256];

        $this->scenario->machine()->send($envelope);

        // Through the canonical form: a MySQL JSON column does not preserve object key order.
        $this->assertSame(
            $before,
            [$envelope->refresh()->fieldSchema()->canonicalJson(), $envelope->field_schema_sha256],
        );
    }

    // ------------------------------------------------------------------------ visible failure

    public function test_an_absent_required_anchor_stops_the_send_and_names_the_field(): void
    {
        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(sellerSignatureText: 'Witness signature:'),
        ]);

        $failure = $this->refusedSend($envelope);

        $this->assertTrue($failure->hasCode(ValidationCode::AnchorNotFound->value));
        $this->assertSame('seller_signature', $failure->problems[0]['field']);
        $this->assertSame('Witness signature:', $failure->problems[0]['anchor_text']);
        $this->assertSame('no match on page 2', $failure->problems[0]['found']);
        $this->assertStringContainsString('"seller_signature"', $failure->getMessage());

        // Nothing moved: not the state, not the schema, and nobody was invited.
        $this->assertSame(EnvelopeState::Draft, $envelope->refresh()->state);
        $this->assertNull($envelope->sent_at);
        $this->assertNull($envelope->fieldSchema()->field('seller_signature')?->anchor?->resolved);
    }

    public function test_an_ambiguous_anchor_stops_the_send(): void
    {
        // "Notes:" occurs twice on page 2 of the fixture, and `sole` means exactly once.
        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(sellerSignatureText: 'Notes:'),
        ]);

        $failure = $this->refusedSend($envelope);

        $this->assertTrue($failure->hasCode(ValidationCode::AnchorAmbiguous->value));
        $this->assertSame('2 matches on page 2', $failure->problems[0]['found']);
        $this->assertSame(EnvelopeState::Draft, $envelope->refresh()->state);
    }

    public function test_an_anchor_failure_is_reported_alongside_every_other_send_problem(): void
    {
        $schema = SigningFixtures::mutateRecipient(
            $this->anchoredSchema(sellerSignatureText: 'Witness signature:'),
            'seller',
            ['email' => 'seller@example.test'],
        );

        $envelope = $this->scenario->preparedDraft(['field_schema' => $schema]);
        $this->scenario->recipient($envelope, 'seller')->forceFill(['email' => 'not-an-address'])->save();

        $failure = $this->refusedSend($envelope);

        // Send is the last cheap moment, so the sender sees the whole list at once rather than
        // discovering the next problem after fixing this one.
        $this->assertContains(ValidationCode::AnchorNotFound->value, $failure->codes());
        $this->assertContains('recipient_missing_email', $failure->codes());
    }

    // ------------------------------------------------------------------------ optional absence

    public function test_an_absent_optional_anchor_omits_the_field_and_records_it_on_the_envelope(): void
    {
        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(notesText: 'Witness signature:', notesAnchorRequired: false),
        ]);

        $this->scenario->machine()->send($envelope);
        $envelope->refresh();

        $this->assertSame(EnvelopeState::Sent, $envelope->state);
        $this->assertNull(
            $envelope->fieldSchema()->field('seller_notes'),
            'An absent optional anchor omits the field; it is never placed at its placeholder rectangle.',
        );

        $this->assertTrue($envelope->hasOmittedAnchorFields());

        // Key-sorted on both sides: a MySQL JSON column does not preserve object key order, and
        // what this record promises is its contents, not a byte layout.
        $recorded = $envelope->omittedAnchorFields()[0];
        ksort($recorded);
        $expected = [
            'field_id' => 'seller_notes',
            'recipient_id' => 'seller',
            'type' => 'text',
            'alias' => null,
            'page' => 2,
            'anchor_text' => 'Witness signature:',
            'occurrence' => 'index 2',
            'reason' => 'optional_anchor_absent',
        ];
        ksort($expected);

        $this->assertCount(1, $envelope->omittedAnchorFields());
        $this->assertSame($expected, $recorded);

        // And in the event, so a reader following the trail sees it too.
        $sent = $this->scenario->sink->payloadFor(EnvelopeEvent::Sent);
        $this->assertSame('seller_notes', $sent['anchor_fields_omitted'][0]['field_id']);
        $this->assertSame(1, $sent['anchors_resolved']);
    }

    public function test_a_value_supplied_for_an_omitted_field_is_discarded_with_it(): void
    {
        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(notesText: 'Witness signature:', notesAnchorRequired: false),
        ]);

        // The sender prefilled the optional field before send; its anchor then turns out not to
        // be in the document.
        $this->scenario->machine()->setSenderValues($envelope, ['seller_notes' => 'Prefilled by the sender.']);

        $this->scenario->machine()->send($envelope->refresh());

        // The field is gone from the agreement, and so is the value: the finalizer captures every
        // row it finds, and a value for a field the agreement does not contain would appear in
        // the evidence as an untyped stray.
        $this->assertNull($envelope->refresh()->fieldSchema()->field('seller_notes'));
        $this->assertSame(0, EnvelopeFieldValue::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('schema_field_id', 'seller_notes')
            ->count());

        // Everything else a sender supplied survives untouched.
        $this->assertGreaterThan(0, EnvelopeFieldValue::query()->where('envelope_id', $envelope->getKey())->count());
    }

    public function test_the_omission_reaches_the_audit_trail(): void
    {
        $machine = new EnvelopeStateMachine(
            app(AuditEnvelopeEventSink::class),
            $this->scenario->assurance,
            app(AnchorResolution::class),
        );

        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(notesText: 'Witness signature:', notesAnchorRequired: false),
        ]);

        $machine->send($envelope);

        $event = AuditEvent::query()->where('action', EnvelopeEvent::Sent->value)->sole();
        $payload = $event->payload;

        $this->assertIsArray($payload);
        $this->assertSame('seller_notes', $payload['anchor_fields_omitted'][0]['field_id']);
        $this->assertSame('optional_anchor_absent', $payload['anchor_fields_omitted'][0]['reason']);
    }

    public function test_nothing_is_recorded_when_nothing_was_omitted(): void
    {
        $envelope = $this->scenario->preparedDraft(['field_schema' => $this->anchoredSchema()]);

        $this->scenario->machine()->send($envelope);

        // Null, not an empty list: nothing was left out, and no anchor ever asked to be.
        $this->assertNull($envelope->refresh()->omitted_anchor_fields);
        $this->assertFalse($envelope->hasOmittedAnchorFields());
    }

    // ------------------------------------------------------------------------ no re-resolution

    public function test_the_document_is_read_once_and_never_again(): void
    {
        $counter = $this->countingResolution();
        $envelope = $this->scenario->preparedDraft(['field_schema' => $this->anchoredSchema()]);

        $this->scenario->machine()->send($envelope);
        $afterSend = $envelope->refresh()->field_schema_sha256;

        $this->scenario->signAs($envelope, $this->scenario->recipient($envelope, 'buyer'));
        $this->scenario->signAs($envelope->refresh(), $this->scenario->recipient($envelope, 'seller'));
        $this->scenario->machine()->markCompleted($envelope->refresh(), 'artifact-1');

        $this->assertSame(1, $counter->calls, 'Resolution happens at send, once, and nowhere else.');
        $this->assertSame($afterSend, $envelope->refresh()->field_schema_sha256);
    }

    public function test_an_already_resolved_anchor_is_not_looked_up_again(): void
    {
        // What the Firma facade produces: the rectangle and the receipt for these exact bytes.
        $envelope = $this->scenario->preparedDraft(['field_schema' => $this->preResolvedSchema()]);
        $before = [$envelope->fieldSchema()->canonicalJson(), $envelope->field_schema_sha256];

        // Remove the bytes. Anything that tried to read the document would fail loudly.
        Storage::disk('documents')->delete($this->scenario->revision->path);

        $this->scenario->machine()->send($envelope);

        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
        $this->assertSame($before, [$envelope->fieldSchema()->canonicalJson(), $envelope->field_schema_sha256]);
    }

    // ------------------------------------------------------------------------ over HTTP

    public function test_the_native_api_reports_an_unplaceable_anchor_as_a_structured_422(): void
    {
        $issued = $this->credential();

        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(sellerSignatureText: 'Witness signature:'),
        ]);

        $response = $this->postJson(
            '/api/v1/envelopes/'.$envelope->public_id.'/send',
            [],
            ['Authorization' => 'Bearer '.$issued->secret],
        );

        $response->assertStatus(422)->assertJsonPath('error.code', 'send_preconditions_failed');

        // The client switches on the code and shows the sender the field: everything it needs
        // is a value, not a substring of a sentence.
        $problem = collect($response->json('error.details.problems'))
            ->firstWhere('code', ValidationCode::AnchorNotFound->value);

        $this->assertIsArray($problem);
        $this->assertSame('seller_signature', $problem['field']);
        $this->assertSame('seller', $problem['recipient']);
        $this->assertSame('Witness signature:', $problem['anchor_text']);
        $this->assertSame('no match on page 2', $problem['found']);
    }

    public function test_the_native_api_publishes_what_was_omitted(): void
    {
        $issued = $this->credential();
        $envelope = $this->scenario->preparedDraft([
            'field_schema' => $this->anchoredSchema(notesText: 'Witness signature:', notesAnchorRequired: false),
        ]);

        $headers = ['Authorization' => 'Bearer '.$issued->secret];

        $this->postJson('/api/v1/envelopes/'.$envelope->public_id.'/send', [], $headers)
            ->assertOk()
            ->assertJsonPath('state', 'sent')
            ->assertJsonPath('omitted_anchor_fields.0.field_id', 'seller_notes')
            ->assertJsonPath('omitted_anchor_fields.0.reason', 'optional_anchor_absent');

        // And it stays visible: this is the answer to "why is there no notes box on this
        // agreement?", asked long after the send.
        $this->getJson('/api/v1/envelopes/'.$envelope->public_id, $headers)
            ->assertOk()
            ->assertJsonPath('omitted_anchor_fields.0.anchor_text', 'Witness signature:');
    }

    // ------------------------------------------------------------------------ helpers

    private function credential(): IssuedServiceCredential
    {
        return app(ServiceCredentialIssuer::class)->issue(
            $this->scenario->workspace,
            'anchor test',
            [Scope::EnvelopesRead->value, Scope::EnvelopesWrite->value],
            AuditActor::system('tests'),
        );
    }

    private function refusedSend(Envelope $envelope): SendPreconditionsFailed
    {
        try {
            $this->scenario->machine()->send($envelope);
        } catch (SendPreconditionsFailed $failure) {
            return $failure;
        }

        $this->fail('Expected the send to be refused.');
    }

    /**
     * A resolution port that counts how often it is asked, wrapping the real one.
     */
    private function countingResolution(): object
    {
        $counter = new class(app(AnchorResolution::class)) implements AnchorResolution
        {
            public int $calls = 0;

            public function __construct(private readonly AnchorResolution $inner) {}

            public function forEnvelope(Envelope $envelope): AnchorResolutionOutcome
            {
                $this->calls++;

                return $this->inner->forEnvelope($envelope);
            }
        };

        app()->instance(AnchorResolution::class, $counter);

        return $counter;
    }

    /**
     * The sequential two-signer fixture with the counterparty's signature placed by anchor and
     * the optional notes field placed against the second "Notes:" on page 2.
     *
     * @return array<string, mixed>
     */
    private function anchoredSchema(
        string $sellerSignatureText = 'Counterparty signature:',
        string $notesText = 'Notes:',
        bool $notesAnchorRequired = true,
    ): array {
        $schema = SigningFixtures::mutateField(SigningFixtures::sequentialTwoSigners(), 'seller_signature', [
            'anchor' => [
                'text' => $sellerSignatureText,
                'occurrence' => 'sole',
                'placement' => AnchorPlacementMode::Replace->value,
                'origin' => 'bottom_left',
                'offset' => ['dx' => 0, 'dy' => 12.5],
            ],
        ]);

        return SigningFixtures::mutateField($schema, 'seller_notes', [
            'anchor' => [
                'text' => $notesText,
                'occurrence' => 2,
                'placement' => AnchorPlacementMode::Replace->value,
                'required' => $notesAnchorRequired,
            ],
        ]);
    }

    /**
     * The same document with the anchor already resolved against this revision's bytes.
     *
     * @return array<string, mixed>
     */
    private function preResolvedSchema(): array
    {
        $resolved = app(AnchorResolution::class)->forEnvelope(
            $this->scenario->draft(['field_schema' => $this->anchoredSchema()]),
        );

        return $resolved->schema->toArray();
    }
}
