<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailContext;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\MailOutbox;
use App\Domain\Delivery\Mail\MailRecipient;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\NonDeliveringMailerException;
use App\Domain\Identity\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticMailContext;
use Tests\TestCase;

/**
 * What enqueueing does, and what it refuses to do.
 *
 * The refusals matter as much as the happy path. A queued row in a deployment that cannot
 * deliver looks exactly like progress, and a message whose context cannot render is a
 * blank paragraph that a queue worker will happily send.
 */
class MailOutboxTest extends TestCase
{
    use RefreshDatabase;

    private MailOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = $this->app->make(MailOutbox::class);
    }

    public function test_enqueue_writes_a_queued_row_and_dispatches_the_send_job(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $context = SyntheticMailContext::for(MailKind::Invitation);

        $mail = $this->outbox->enqueue(
            kind: MailKind::Invitation,
            recipient: new MailRecipient('avery@counterparty.test', 'Avery Counterparty'),
            context: $context,
            workspace: $workspace,
        );

        $this->assertSame(MailState::Queued, $mail->state);
        $this->assertSame(MailKind::Invitation, $mail->kind);
        $this->assertSame('avery@counterparty.test', $mail->to_email);
        $this->assertSame('Avery Counterparty', $mail->to_name);
        $this->assertSame($workspace->getKey(), $mail->workspace_id);
        $this->assertSame(0, $mail->attempts);
        $this->assertNull($mail->message_id);
        $this->assertNull($mail->last_error);
        $this->assertNotNull($mail->state_changed_at);
        $this->assertSame(26, strlen($mail->public_id));

        // The subject on the row is the subject the Mailable will send, not a second copy.
        $this->assertSame(
            MailKind::Invitation->mailable($context)->subjectLine(),
            $mail->subject,
        );

        // The context round-trips, so a worker renders from exactly what was recorded.
        $this->assertSame($context->toArray(), $mail->fresh()?->context);

        Queue::assertPushed(
            SendOutboundMail::class,
            fn (SendOutboundMail $job): bool => $job->mailPublicId === $mail->public_id,
        );
    }

    public function test_enqueue_records_an_app_event_that_does_not_claim_delivery(): void
    {
        Queue::fake();

        $mail = $this->enqueueInvitation();
        $event = $mail->events()->sole();

        $this->assertSame(MailEventSource::App, $event->source);
        $this->assertSame('queued', $event->event);
        $this->assertFalse($event->isOrphan());
        // The application can never be the source of a delivery claim.
        $this->assertFalse($event->source->canReportDelivery());
    }

    public function test_enqueue_attaches_the_cause_through_the_morph_without_knowing_what_it_is(): void
    {
        Queue::fake();

        // Any model at all: the outbox is built before envelopes exist and must not need to
        // know what one is to mail about it.
        $related = Workspace::factory()->create();

        $mail = $this->outbox->enqueue(
            kind: MailKind::Reminder,
            recipient: new MailRecipient('avery@counterparty.test'),
            context: SyntheticMailContext::for(MailKind::Reminder),
            related: $related,
        );

        $this->assertSame($related->getMorphClass(), $mail->related_type);
        $this->assertSame((string) $related->getKey(), $mail->related_id);
        $this->assertTrue($mail->related()->is($related));
    }

    public function test_operator_mail_needs_no_workspace(): void
    {
        Queue::fake();

        $mail = $this->outbox->enqueue(
            kind: MailKind::AdminFailure,
            recipient: new MailRecipient('operations@example.test', 'Operations'),
            context: SyntheticMailContext::for(MailKind::AdminFailure),
        );

        $this->assertNull($mail->workspace_id);
        $this->assertSame(MailState::Queued, $mail->state);
    }

    #[DataProvider('nonDeliveringMailers')]
    public function test_production_refuses_to_enqueue_through_a_non_delivering_mailer(string $mailer): void
    {
        Queue::fake();

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', $mailer);

        try {
            $this->enqueueInvitation();
            $this->fail("Enqueueing through the '{$mailer}' mailer in production should have been refused.");
        } catch (NonDeliveringMailerException $exception) {
            $this->assertStringContainsString($mailer, $exception->getMessage());
            $this->assertStringContainsString('not a delivered message', $exception->getMessage());
        }

        // Nothing was written and nothing was queued: a refusal that left a row behind
        // would be indistinguishable from a message waiting to be sent.
        $this->assertSame(0, OutboundMail::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDeliveringMailers(): array
    {
        return [
            'log' => ['log'],
            'array' => ['array'],
            // A composite whose second leg is `log`, which is the subtle version of the
            // same mistake.
            'failover' => ['failover'],
        ];
    }

    #[DataProvider('deliveringMailers')]
    public function test_production_accepts_the_delivering_mailers(string $mailer): void
    {
        Queue::fake();

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', $mailer);

        $mail = $this->enqueueInvitation();

        $this->assertSame(MailState::Queued, $mail->state);
        $this->assertSame($mailer, $mail->events()->sole()->payload['mailer'] ?? null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deliveringMailers(): array
    {
        return [
            'hybrid' => ['hybrid'],
            'brevo' => ['brevo'],
            'smtp' => ['smtp'],
            'ses' => ['ses'],
        ];
    }

    public function test_a_non_delivering_mailer_is_fine_outside_production(): void
    {
        Queue::fake();

        config()->set('mail.default', 'log');

        $this->assertSame(MailState::Queued, $this->enqueueInvitation()->state);
    }

    public function test_enqueue_refuses_a_context_the_template_cannot_render(): void
    {
        Queue::fake();

        $this->expectException(InvalidArgumentException::class);

        $this->outbox->enqueue(
            kind: MailKind::Invitation,
            recipient: new MailRecipient('avery@counterparty.test'),
            // No signing link, which is the whole content of an invitation.
            context: new MailContext(
                recipientName: 'Avery Counterparty',
                senderName: 'Example Holdings',
                agreementTitle: 'Mutual Nondisclosure Agreement',
            ),
        );
    }

    public function test_resend_creates_a_new_row_linked_to_the_original_and_leaves_it_alone(): void
    {
        Queue::fake();

        $original = OutboundMail::factory()
            ->inState(MailState::Bounced)
            ->create(['message_id' => 'original@mail.example.test', 'attempts' => 3]);

        $copy = $this->outbox->resend($original);

        $this->assertNotSame($original->public_id, $copy->public_id);
        $this->assertSame($original->getKey(), $copy->resent_from_id);
        $this->assertTrue($copy->resentFrom()->is($original));
        $this->assertSame(MailState::Queued, $copy->state);
        $this->assertSame(0, $copy->attempts);
        // A fresh message has not been named by a provider yet.
        $this->assertNull($copy->message_id);
        $this->assertSame($original->to_email, $copy->to_email);
        $this->assertSame($original->subject, $copy->subject);
        $this->assertSame($original->context, $copy->context);

        // The original is evidence of what happened; resending must not rewrite it.
        $original->refresh();
        $this->assertSame(MailState::Bounced, $original->state);
        $this->assertSame(3, $original->attempts);
        $this->assertSame('original@mail.example.test', $original->message_id);
        $this->assertSame(
            [$copy->public_id],
            $original->events()->where('event', 'resent')->pluck('payload')->map(
                fn (array $payload): string => $payload['resent_as'],
            )->all(),
        );

        Queue::assertPushed(
            SendOutboundMail::class,
            fn (SendOutboundMail $job): bool => $job->mailPublicId === $copy->public_id,
        );
    }

    public function test_the_send_job_is_unique_per_message(): void
    {
        Queue::fake();

        $mail = $this->enqueueInvitation();
        $job = new SendOutboundMail($mail->public_id);

        $this->assertSame($mail->public_id, $job->uniqueId());
        $this->assertGreaterThan(0, $job->uniqueFor());
    }

    private function enqueueInvitation(): OutboundMail
    {
        return $this->outbox->enqueue(
            kind: MailKind::Invitation,
            recipient: new MailRecipient('avery@counterparty.test', 'Avery Counterparty'),
            context: SyntheticMailContext::for(MailKind::Invitation),
        );
    }
}
