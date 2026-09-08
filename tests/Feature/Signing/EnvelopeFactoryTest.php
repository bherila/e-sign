<?php

declare(strict_types=1);

namespace Tests\Feature\Signing;

use App\Domain\Evidence\Sealing\AssuranceLevel;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Preparation\Schema\FieldSchemaDocument;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeFactory;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\RecipientState;
use App\Domain\Signing\Envelopes\SigningMode;
use App\Domain\Signing\Exceptions\InvalidEnvelopeSnapshot;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SigningFixtures;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Creating an envelope copies its source and then forgets it.
 *
 * docs/HANDOFF.md section 6: "Sending copies the selected version into the envelope. Later
 * template changes never mutate existing requests."
 */
class EnvelopeFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_copies_the_snapshot_into_an_immutable_draft(): void
    {
        $scenario = SigningScenario::create();

        $envelope = $scenario->draft();

        $this->assertSame(EnvelopeState::Draft, $envelope->state);
        $this->assertSame(1, $envelope->version);
        $this->assertSame($scenario->revision->sha256, $envelope->document_sha256);
        $this->assertSame($scenario->revision->getKey(), $envelope->document_revision_id);
        $this->assertSame(SigningFixtures::CONSENT_VERSION, $envelope->consent_policy_version);
        $this->assertSame(AssuranceLevel::PadesBB, $envelope->assurance_level);
        $this->assertSame(SigningMode::Sequential, $envelope->signing_mode);
        $this->assertSame(['signature_style' => 'typed_or_drawn'], $envelope->render_settings);
        $this->assertSame($scenario->user->getKey(), $envelope->created_by);
        $this->assertNotSame('', $envelope->public_id);
        $this->assertNull($envelope->sent_at);
        $this->assertNull($envelope->content_frozen_at);
    }

    public function test_the_stored_schema_digest_is_the_digest_of_its_canonical_form(): void
    {
        $envelope = SigningScenario::create()->draft();

        $canonical = FieldSchemaDocument::fromArray(SigningFixtures::sequentialTwoSigners())->canonicalJson();

        $this->assertSame(hash('sha256', $canonical), $envelope->field_schema_sha256);
        $this->assertSame($canonical, $envelope->fieldSchema()->canonicalJson());
    }

    public function test_it_creates_recipients_from_the_schema_and_its_signing_order(): void
    {
        $scenario = SigningScenario::create();

        $envelope = $scenario->draft();
        $recipients = $envelope->recipients()->get();

        $this->assertCount(2, $recipients);
        $this->assertSame(['buyer', 'seller'], $recipients->pluck('schema_recipient_id')->all());
        $this->assertSame([1, 2], $recipients->pluck('order_index')->all());
        $this->assertSame(
            [RecipientState::Pending, RecipientState::Pending],
            $recipients->pluck('state')->all(),
        );
        $this->assertSame(
            ['buyer@example.test', 'seller@example.test'],
            $recipients->pluck('email')->all(),
        );

        $buyer = $scenario->recipient($envelope, 'buyer');
        // assertEquals, not assertSame: a MySQL `JSON` column does not preserve object key
        // order, so the array that comes back out is not necessarily ordered the way it went
        // in. Nothing reads this snapshot positionally or hashes it, so the order is not part
        // of the contract — but asserting it would make the suite fail on one engine only.
        $this->assertEquals([
            'source' => 'field_schema',
            'schema_recipient_id' => 'buyer',
            'name' => 'Example Buyer',
            'email' => 'buyer@example.test',
            'role' => 'Buyer',
        ], $buyer->identity_snapshot);
    }

    public function test_parallel_recipients_all_share_the_first_stage(): void
    {
        $scenario = SigningScenario::create();

        $envelope = $scenario->draft([
            'field_schema' => SigningFixtures::parallelTwoSigners(),
            'signing_mode' => 'parallel',
        ]);

        $this->assertSame([1, 1], $envelope->recipients()->pluck('order_index')->all());
    }

    public function test_it_records_a_created_event(): void
    {
        $scenario = SigningScenario::create();

        $envelope = $scenario->draft();

        $this->assertSame([EnvelopeEvent::Created->value], $scenario->sink->names());
        $this->assertSame(
            $envelope->field_schema_sha256,
            $scenario->sink->payloadFor(EnvelopeEvent::Created)['field_schema_sha256'],
        );
    }

    public function test_the_default_sink_writes_the_created_event_to_the_audit_trail(): void
    {
        $scenario = SigningScenario::create();

        // The production wiring, not the recording double.
        $envelope = app(EnvelopeFactory::class)
            ->fromSnapshot($scenario->workspace, $scenario->snapshot(), $scenario->user);

        $event = AuditEvent::query()->where('action', EnvelopeEvent::Created->value)->sole();

        $this->assertSame(Envelope::class, $event->subject_type);
        $this->assertSame((string) $envelope->getKey(), $event->subject_id);
        $this->assertSame($envelope->public_id, $event->payload['envelope']);
    }

    public function test_it_refuses_a_snapshot_whose_declared_digest_is_not_the_revisions(): void
    {
        $scenario = SigningScenario::create();

        $this->expectException(InvalidEnvelopeSnapshot::class);
        $this->expectExceptionMessageMatches('/declares document digest/');

        $scenario->draft(['document_sha256' => hash('sha256', 'some other bytes')]);
    }

    public function test_it_refuses_a_revision_from_another_workspace(): void
    {
        $mine = SigningScenario::create();
        $theirs = SigningScenario::create();

        try {
            $mine->factory()->fromSnapshot(
                $mine->workspace,
                $mine->snapshot([
                    'document_revision_id' => $theirs->revision->getKey(),
                    'document_sha256' => $theirs->revision->sha256,
                ]),
                $mine->user,
            );
            $this->fail('A cross-workspace revision must not produce an envelope.');
        } catch (InvalidEnvelopeSnapshot $e) {
            $this->assertSame('document_not_in_workspace', $e->code());
        }

        $this->assertSame(0, Envelope::query()->count());
    }

    public function test_it_refuses_parallel_mode_with_a_multi_stage_signing_order(): void
    {
        $scenario = SigningScenario::create();

        try {
            $scenario->draft(['signing_mode' => 'parallel']);
            $this->fail('Parallel mode with two stages is two contradictory instructions.');
        } catch (InvalidEnvelopeSnapshot $e) {
            $this->assertSame('parallel_with_multiple_stages', $e->code());
        }

        $this->assertSame(0, Envelope::query()->count());
    }

    public function test_the_snapshot_columns_refuse_to_change_after_creation(): void
    {
        $envelope = SigningScenario::create()->draft();

        $envelope->title = 'A new title is fine';
        $envelope->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/snapshot taken at creation/');

        $envelope->consent_policy_version = 'consent-2027-01';
        $envelope->save();
    }
}
