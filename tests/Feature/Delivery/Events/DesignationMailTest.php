<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Events;

use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\EnvelopeMailScheduler;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Signing\Contracts\AnchorResolution;
use App\Domain\Signing\Envelopes\EnvelopeEvent;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Mail\InvitationMail;
use App\Mail\ReminderMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Signing\EnvelopeDesignationTest;
use Tests\Support\FakeDownloadUrlMinter;
use Tests\Support\FakeSigningUrlMinter;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * Who is written to, and in what words, when an envelope has an approver and a cc recipient
 * (issue #169).
 *
 * An approver is invited in order and asked to approve. A cc recipient is never invited,
 * never reminded, and is not told about a cancellation of an agreement they were never shown;
 * the completed copy is the one message they get.
 */
class DesignationMailTest extends TestCase
{
    use RefreshDatabase;

    private SigningScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendOutboundMail::class, DispatchOutboxEvent::class]);

        $this->scenario = SigningScenario::create();
        $this->app->instance(SigningUrlMinter::class, new FakeSigningUrlMinter);
        $this->app->instance(DownloadUrlMinter::class, new FakeDownloadUrlMinter);
    }

    public function test_the_approver_is_invited_in_turn_and_asked_to_approve(): void
    {
        $envelope = $this->scenario->preparedDraft(['field_schema' => EnvelopeDesignationTest::schemaWithApproverAndCc()]);
        $this->machine()->send($envelope);

        $this->assertSame(['signer@example.test'], $this->invitedAddresses());

        $this->scenario->signAs($envelope, $this->scenario->recipient($envelope, 'signer'), 'session-signer');
        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::RecipientSigned);

        $this->assertSame(['signer@example.test', 'manager@example.test'], $this->invitedAddresses());

        $mail = OutboundMail::query()->where('to_email', 'manager@example.test')->firstOrFail();
        $context = MailContext::fromArray($mail->context);

        $this->assertTrue($context->asksForApproval());
        $invitation = new InvitationMail($context);
        $this->assertStringEndsWith('asked you to approve "'.$envelope->title.'"', $invitation->subjectLine());
        $this->assertStringContainsString('Review and approve', $invitation->render());

        // The signer's own invitation still asks for a signature.
        $signerContext = MailContext::fromArray(
            OutboundMail::query()->where('to_email', 'signer@example.test')->firstOrFail()->context,
        );
        $this->assertFalse($signerContext->asksForApproval());
        $this->assertStringContainsString('Review and sign', (new InvitationMail($signerContext))->render());
    }

    public function test_a_reminder_to_an_approver_asks_for_the_approval(): void
    {
        $context = new MailContext(
            recipientName: 'Synthetic Manager',
            senderName: 'Synthetic Sender',
            agreementTitle: 'Synthetic agreement',
            actionUrl: 'https://esign.example.test/sign/01JSYNTHETIC?token=abc',
            requestedAction: MailContext::ACTION_APPROVE,
        );

        $reminder = new ReminderMail($context);

        $this->assertSame('Reminder: "Synthetic agreement" is waiting for your approval', $reminder->subjectLine());
        $this->assertStringContainsString('waiting for your approval', $reminder->render());
    }

    public function test_an_unknown_requested_action_is_refused_when_written_and_ignored_when_read(): void
    {
        try {
            new MailContext(recipientName: 'Synthetic', requestedAction: 'countersign');
            $this->fail('An unknown requested action must be refused.');
        } catch (\InvalidArgumentException) {
            // Refused at the constructor.
        }

        $this->assertFalse(MailContext::fromArray([
            'recipient_name' => 'Synthetic',
            'requested_action' => 'countersign',
        ])->asksForApproval());
    }

    public function test_the_cc_recipient_is_never_invited_and_is_sent_the_completed_copy(): void
    {
        $envelope = $this->scenario->preparedDraft(['field_schema' => EnvelopeDesignationTest::schemaWithApproverAndCc()]);
        $this->machine()->send($envelope);
        $this->scenario->signAs($envelope, $this->scenario->recipient($envelope, 'signer'), 'session-signer');
        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::RecipientSigned);
        $this->scenario->signAs($envelope, $this->scenario->recipient($envelope, 'manager'), 'session-manager');

        $this->assertNotContains('records@example.test', $this->invitedAddresses());
        $this->assertNull($this->scenario->recipient($envelope, 'records')->invited_at);

        $this->scheduler()->schedule($envelope->refresh(), EnvelopeEvent::Completed);

        $completed = OutboundMail::query()->where('kind', MailKind::Completed->value)->pluck('to_email')->all();
        $this->assertContains('records@example.test', $completed);
        $this->assertContains('manager@example.test', $completed);
    }

    public function test_the_cc_recipient_is_not_told_about_a_cancellation(): void
    {
        $envelope = $this->scenario->preparedDraft(['field_schema' => EnvelopeDesignationTest::schemaWithApproverAndCc()]);
        $this->machine()->send($envelope);
        $this->machine()->cancel($envelope->refresh(), 'Superseded.');

        $cancelled = OutboundMail::query()->where('kind', MailKind::Cancelled->value)->pluck('to_email')->all();

        $this->assertContains('signer@example.test', $cancelled);
        $this->assertNotContains('records@example.test', $cancelled);
    }

    /** @return list<string> */
    private function invitedAddresses(): array
    {
        return OutboundMail::query()
            ->where('kind', MailKind::Invitation->value)
            ->orderBy('id')
            ->pluck('to_email')
            ->all();
    }

    private function scheduler(): EnvelopeMailScheduler
    {
        return $this->app->make(EnvelopeMailScheduler::class);
    }

    private function machine(): EnvelopeStateMachine
    {
        return new EnvelopeStateMachine(
            $this->app->make(DeliveryEnvelopeEventSink::class),
            $this->scenario->assurance,
            $this->app->make(AnchorResolution::class),
        );
    }
}
