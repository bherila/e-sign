<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence\Retention;

use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Evidence\Finalization\Artifacts\Artifact;
use App\Domain\Evidence\Finalization\Artifacts\ArtifactKind;
use App\Domain\Evidence\Retention\Exceptions\RetentionRefused;
use App\Domain\Evidence\Retention\RecipientEraser;
use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Models\RecipientAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RetentionScenario;
use Tests\TestCase;

/**
 * Erasure removes the service's record *about* a person and leaves the agreement they
 * entered into exactly as it was.
 *
 * The assertions that matter most are the negative ones: the attestation chain, the field
 * values, and the published bytes are untouched, and their digests still agree. "We erased
 * the signature" is a sentence somebody will ask for, and these tests are why the answer is
 * no.
 */
class RecipientErasureTest extends TestCase
{
    use RefreshDatabase;

    public function test_erasure_tombstones_the_contact_fields(): void
    {
        $scenario = RetentionScenario::finalized();
        $recipient = $scenario->envelope->refresh()->recipients()->firstOrFail();
        $originalEmail = $recipient->email;
        $originalName = $recipient->name;

        $result = app(RecipientEraser::class)->erase(
            $recipient,
            'Article 17 request, ref 2026-88',
            AuditActor::console('esign:privacy:erase-recipient'),
        );

        $recipient->refresh();

        $this->assertSame(RecipientEraser::NAME_TOMBSTONE, $recipient->name);
        $this->assertSame(RecipientEraser::tombstoneEmail($recipient->public_id), $recipient->email);
        $this->assertStringEndsWith('.invalid', $recipient->email);
        $this->assertTrue($recipient->identity_snapshot['erased']);

        $this->assertNotSame($originalEmail, $recipient->email);
        $this->assertNotSame($originalName, $recipient->name);
        $this->assertSame($recipient->public_id, $result['recipient']);
    }

    public function test_the_attestations_digests_and_chain_are_untouched(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $recipient = $envelope->recipients()->firstOrFail();

        $before = RecipientAttestation::query()
            ->orderBy('id')
            ->get(['id', 'attestation_sha256', 'prev_attestation_sha256', 'document_sha256', 'material_values_sha256'])
            ->toArray();

        $this->assertNotEmpty($before);

        app(RecipientEraser::class)->erase($recipient, 'Erasure request', AuditActor::console('test'));

        $after = RecipientAttestation::query()
            ->orderBy('id')
            ->get(['id', 'attestation_sha256', 'prev_attestation_sha256', 'document_sha256', 'material_values_sha256'])
            ->toArray();

        $this->assertSame($before, $after);
    }

    public function test_the_field_values_and_published_artifacts_still_match_their_digests(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $recipient = $envelope->recipients()->firstOrFail();

        $values = $envelope->fieldValues()->orderBy('id')->pluck('value_sha256', 'schema_field_id')->all();
        $artifacts = Artifact::query()->orderBy('id')->get();

        app(RecipientEraser::class)->erase($recipient, 'Erasure request', AuditActor::console('test'));

        $this->assertSame($values, $envelope->refresh()->fieldValues()->orderBy('id')->pluck('value_sha256', 'schema_field_id')->all());

        // The bytes on the disk are the bytes the digests describe, still.
        foreach ($artifacts as $artifact) {
            $this->assertTrue(Storage::disk('documents')->exists($artifact->path));
            $this->assertSame(
                $artifact->sha256,
                hash('sha256', (string) Storage::disk('documents')->get($artifact->path)),
            );
        }

        $this->assertSame(
            $artifacts->pluck('sha256')->all(),
            Artifact::query()->orderBy('id')->pluck('sha256')->all(),
        );
    }

    public function test_the_executed_pdf_still_carries_the_signer_name_because_it_is_the_instrument(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $recipient = $envelope->recipients()->firstOrFail();
        $executed = Artifact::query()->where('kind', ArtifactKind::ExecutedPdf->value)->sole();
        $before = (string) Storage::disk('documents')->get($executed->path);

        app(RecipientEraser::class)->erase($recipient, 'Erasure request', AuditActor::console('test'));

        $this->assertSame($before, (string) Storage::disk('documents')->get($executed->path));
    }

    public function test_the_audit_event_records_what_was_erased_and_never_the_erased_value(): void
    {
        $scenario = RetentionScenario::finalized();
        $recipient = $scenario->envelope->refresh()->recipients()->firstOrFail();
        $originalEmail = $recipient->email;

        app(RecipientEraser::class)->erase($recipient, 'Article 17 request, ref 2026-88', AuditActor::console('test'));

        $event = AuditEvent::query()->where('action', RecipientEraser::ERASED)->sole();

        $this->assertSame('Article 17 request, ref 2026-88', $event->payload['reason']);
        $this->assertContains('envelope_recipients.email', $event->payload['erased_columns']);
        $this->assertArrayHasKey('recipient_attestations', $event->payload['retained']);

        // An erasure whose own audit trail quotes the address has erased nothing.
        $this->assertStringNotContainsString($originalEmail, json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    public function test_transactional_messages_to_that_person_are_tombstoned_without_losing_the_record_of_contact(): void
    {
        $scenario = RetentionScenario::finalized();
        $recipient = $scenario->envelope->refresh()->recipients()->firstOrFail();

        $mail = OutboundMail::query()->create([
            'workspace_id' => $scenario->signing->workspace->getKey(),
            'kind' => MailKind::Invitation,
            'to_email' => $recipient->email,
            'to_name' => $recipient->name,
            'subject' => 'Please sign the synthetic mutual NDA',
            'context' => ['recipient_name' => $recipient->name, 'agreement_title' => 'Synthetic mutual NDA'],
            'state' => MailState::SentToProvider,
            'state_changed_at' => now(),
            'related_type' => $recipient->getMorphClass(),
            'related_id' => (string) $recipient->getKey(),
        ]);

        app(RecipientEraser::class)->erase($recipient, 'Erasure request', AuditActor::console('test'));

        $mail->refresh();

        $this->assertSame(RecipientEraser::tombstoneEmail($recipient->public_id), $mail->to_email);
        $this->assertSame(RecipientEraser::NAME_TOMBSTONE, $mail->to_name);
        $this->assertSame(RecipientEraser::NAME_TOMBSTONE, $mail->context['recipient_name']);

        // The fact that a message was sent, and its state, are not personal data.
        $this->assertSame(MailState::SentToProvider, $mail->state);
        $this->assertSame('Synthetic mutual NDA', $mail->context['agreement_title']);
    }

    public function test_erasure_needs_a_reason_and_refuses_a_second_run(): void
    {
        $scenario = RetentionScenario::finalized();
        $recipient = $scenario->envelope->refresh()->recipients()->firstOrFail();
        $eraser = app(RecipientEraser::class);

        try {
            $eraser->erase($recipient, '  ', AuditActor::console('test'));
            $this->fail('An erasure with no reason should be refused.');
        } catch (RetentionRefused $refusal) {
            $this->assertStringContainsString('needs a reason', $refusal->getMessage());
        }

        $eraser->erase($recipient, 'Erasure request', AuditActor::console('test'));

        $this->expectException(RetentionRefused::class);
        $eraser->erase($recipient->refresh(), 'Erasure request', AuditActor::console('test'));
    }

    /**
     * docs/security/review-2026-09.md finding B-7.
     *
     * The operation's justification is that contact data has no evidential role *once the
     * agreement is executed*. Nothing tested that condition. Erasing a live envelope's
     * recipient rewrites the address invitations and one-time codes go to, so the signer
     * becomes unreachable and the agreement hangs until expiry — and if the other parties
     * finish, `FinalizationInput` reads the tombstone at render time and the sealed executed
     * PDF names that party as "Erased recipient", permanently, under the service seal.
     */
    public function test_a_recipient_of_a_live_envelope_cannot_be_erased(): void
    {
        $scenario = RetentionScenario::finalized();
        $envelope = $scenario->envelope->refresh();
        $recipient = $envelope->recipients()->firstOrFail();
        $original = $recipient->email;

        // Same rows, wound back to a state the agreement can still move out of.
        $envelope->forceFill(['state' => EnvelopeState::InProgress->value])->save();

        try {
            app(RecipientEraser::class)->erase($recipient, 'Article 17 request', AuditActor::console('test'));
            $this->fail('A live agreement still needs to be able to reach its signer.');
        } catch (RetentionRefused $refusal) {
            $this->assertStringContainsString('terminal state', $refusal->getMessage());
        }

        $this->assertSame($original, $recipient->refresh()->email);
        $this->assertSame(0, AuditEvent::query()->where('action', 'privacy.recipient.erased')->count());
    }

    /** Every terminal state is erasable, including the unhappy ones. */
    public function test_a_cancelled_or_expired_agreement_can_still_have_its_recipient_erased(): void
    {
        foreach ([EnvelopeState::Cancelled, EnvelopeState::Expired] as $state) {
            $scenario = RetentionScenario::finalized();
            $envelope = $scenario->envelope->refresh();
            $envelope->forceFill(['state' => $state->value])->save();

            $recipient = $envelope->recipients()->firstOrFail();

            app(RecipientEraser::class)->erase($recipient, 'Article 17 request', AuditActor::console('test'));

            $this->assertStringContainsString('@erased.invalid', $recipient->refresh()->email);
        }
    }

    public function test_the_command_erases_and_says_what_it_kept(): void
    {
        $scenario = RetentionScenario::finalized();
        $recipient = $scenario->envelope->refresh()->recipients()->firstOrFail();

        $this->artisan('esign:privacy:erase-recipient', [
            'recipient' => $recipient->public_id,
            '--reason' => 'Article 17 request',
        ])
            ->expectsOutputToContain('has been erased')
            ->expectsOutputToContain('attestation chain')
            ->assertSuccessful();

        $this->assertSame(RecipientEraser::NAME_TOMBSTONE, $recipient->refresh()->name);
    }

    public function test_the_command_refuses_an_unknown_recipient(): void
    {
        $this->artisan('esign:privacy:erase-recipient', ['recipient' => 'nope', '--reason' => 'x'])
            ->assertFailed();
    }
}
