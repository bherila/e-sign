<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailErrorRedactor;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\NonDeliveringMailerException;
use App\Domain\Delivery\Mail\OutboundMailSender;
use App\Domain\Delivery\Mail\ProductionMailerGuard;
use App\Domain\Evidence\Retention\RestoreDrill;
use App\Mail\InvitationMail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * The send path: what a successful hand-off records, what a failed attempt records, and
 * what happens when the queue gives up.
 *
 * The distinction under test throughout is that `sent_to_provider` is the strongest thing
 * this application can say about a message on its own. Nothing here produces `delivered`,
 * because nothing here is a provider.
 */
class SendOutboundMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_send_records_sent_to_provider_and_the_provider_message_id(): void
    {
        // The real array mailer rather than Mail::fake(), because the Message-ID is set by
        // the Symfony transport and a fake never produces a SentMessage to read it from.
        config()->set('mail.default', 'array');

        $mail = OutboundMail::factory()->create();

        $this->app->make(OutboundMailSender::class)->send($mail);

        $mail->refresh();

        $this->assertSame(MailState::SentToProvider, $mail->state);
        $this->assertSame('array', $mail->mailer);
        $this->assertSame(1, $mail->attempts);
        $this->assertNull($mail->last_error);
        $this->assertNotNull($mail->message_id);

        // The stored id is the transport's, normalized: no angle brackets, lowercased.
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        /** @var SentMessage $sent */
        $sent = $transport->messages()->last();
        $this->assertSame(strtolower(trim($sent->getMessageId(), '<>')), $mail->message_id);
        $this->assertStringNotContainsString('<', (string) $mail->message_id);

        $event = $mail->events()->where('event', 'sent_to_provider')->sole();
        $this->assertSame(MailEventSource::App, $event->source);
        $this->assertTrue($event->payload['has_message_id']);
        $this->assertSame($mail->message_id, $event->message_id);

        // `sent_to_provider` is not delivery, and the state itself has to say so.
        $this->assertFalse($mail->state->meansMailboxAccepted());
    }

    public function test_the_message_that_goes_out_is_the_kind_and_recipient_on_the_row(): void
    {
        Mail::fake();

        $mail = OutboundMail::factory()->create([
            'to_email' => 'avery@counterparty.test',
            'to_name' => 'Avery Counterparty',
        ]);

        $this->app->make(OutboundMailSender::class)->send($mail);

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mailable) use ($mail): bool {
            return $mailable->hasTo('avery@counterparty.test', 'Avery Counterparty')
                && $mailable->subjectLine() === $mail->subject;
        });

        $this->assertSame(MailState::SentToProvider, $mail->fresh()?->state);
    }

    public function test_the_job_sends_a_queued_message(): void
    {
        Mail::fake();

        $mail = OutboundMail::factory()->create();

        $this->runJobFor($mail);

        Mail::assertSent(InvitationMail::class);
        $this->assertSame(MailState::SentToProvider, $mail->fresh()?->state);
    }

    public function test_the_job_will_not_send_a_message_that_has_already_left(): void
    {
        Mail::fake();

        $mail = OutboundMail::factory()->sentToProvider()->create();

        $this->runJobFor($mail);

        // Re-sending would put a second copy of the same invitation, with the same link, in
        // the recipient's mailbox.
        Mail::assertNothingSent();
        $this->assertSame(MailState::SentToProvider, $mail->fresh()?->state);
    }

    public function test_the_job_tolerates_a_message_that_no_longer_exists(): void
    {
        Mail::fake();

        (new SendOutboundMail('01JQZX9K7M4N2P5R8T3V6W1Y0B'))->handle($this->app->make(OutboundMailSender::class));

        Mail::assertNothingSent();
        $this->assertSame(0, OutboundMail::query()->count());
    }

    public function test_a_failed_attempt_stays_queued_records_a_redacted_error_and_rethrows(): void
    {
        $mail = OutboundMail::factory()->create();

        $sender = $this->senderThatThrows(new TransportException(
            'Expected response code 250 but got code "550", with message "550 <avery@counterparty.test> rejected"'
        ));

        try {
            $sender->send($mail);
            $this->fail('A transport failure must propagate so the queue can retry it.');
        } catch (TransportException) {
            // Expected: the queue, not the sender, decides whether to try again.
        }

        $mail->refresh();

        // Still queued, because nothing has been handed over. Calling this anything else
        // would be a claim the application cannot support.
        $this->assertSame(MailState::Queued, $mail->state);
        $this->assertSame(1, $mail->attempts);
        $this->assertNotNull($mail->last_error);
        $this->assertStringNotContainsString('avery@counterparty.test', (string) $mail->last_error);
        $this->assertStringContainsString('550', (string) $mail->last_error);

        $event = $mail->events()->where('event', 'attempt_failed')->sole();
        $this->assertSame(1, $event->payload['attempt']);
        $this->assertStringNotContainsString('counterparty.test', json_encode($event->payload) ?: '');
    }

    public function test_repeated_attempts_accumulate_without_moving_the_state(): void
    {
        $mail = OutboundMail::factory()->create();
        $sender = $this->senderThatThrows(new TransportException('Connection could not be established'));

        foreach ([1, 2, 3] as $expectedAttempts) {
            try {
                $sender->send($mail);
            } catch (TransportException) {
                // Each attempt is recorded; only the queue decides when to stop.
            }

            $mail->refresh();
            $this->assertSame($expectedAttempts, $mail->attempts);
            $this->assertSame(MailState::Queued, $mail->state);
        }

        $this->assertCount(3, $mail->events()->where('event', 'attempt_failed')->get());
    }

    public function test_the_final_failure_writes_failed_with_a_redacted_reason(): void
    {
        $mail = OutboundMail::factory()->create(['attempts' => 5]);

        (new SendOutboundMail($mail->public_id))->failed(new TransportException(
            'Could not authenticate with token abcdef0123456789abcdef0123456789 for avery@counterparty.test'
        ));

        $mail->refresh();

        $this->assertSame(MailState::Failed, $mail->state);
        $this->assertTrue($mail->state->isTerminalForSending());
        $this->assertStringNotContainsString('avery@counterparty.test', (string) $mail->last_error);
        $this->assertStringNotContainsString('abcdef0123456789abcdef0123456789', (string) $mail->last_error);

        $event = $mail->events()->where('event', 'failed')->sole();
        $this->assertSame(MailEventSource::App, $event->source);
        $this->assertSame(5, $event->payload['attempts']);
    }

    public function test_the_final_failure_does_not_overwrite_a_message_that_did_go_out(): void
    {
        // The ordering that produces this: the job succeeded, and something after the send
        // — a serialization error, a worker timeout — failed the job anyway.
        $mail = OutboundMail::factory()->sentToProvider()->create();

        (new SendOutboundMail($mail->public_id))->failed(new TransportException('worker timeout'));

        $this->assertSame(MailState::SentToProvider, $mail->fresh()?->state);
    }

    public function test_the_failure_hook_survives_a_missing_exception(): void
    {
        $mail = OutboundMail::factory()->create();

        (new SendOutboundMail($mail->public_id))->failed(null);

        $mail->refresh();

        $this->assertSame(MailState::Failed, $mail->state);
        $this->assertNotNull($mail->last_error);
    }

    public function test_the_sender_refuses_a_non_delivering_mailer_even_after_the_row_was_queued(): void
    {
        $mail = OutboundMail::factory()->create();

        // The configuration changed after the message was accepted, which is exactly how a
        // long-running worker would otherwise get to claim a send through `log`.
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('mail.default', 'log');

        $this->expectException(NonDeliveringMailerException::class);

        try {
            $this->app->make(OutboundMailSender::class)->send($mail);
        } finally {
            $mail->refresh();
            $this->assertSame(MailState::Queued, $mail->state);
            $this->assertSame(0, $mail->attempts);
        }
    }

    public function test_retries_and_backoff_come_from_configuration(): void
    {
        config()->set('esign.mail.tries', 7);
        config()->set('esign.mail.backoff', [30, 90, 0, 'nonsense']);

        $job = new SendOutboundMail('01JQZX9K7M4N2P5R8T3V6W1Y0B');

        $this->assertSame(7, $job->tries());
        // Zero and unparseable entries are dropped: an immediate retry is not a backoff.
        $this->assertSame([30, 90], $job->backoff());

        config()->set('esign.mail.backoff', []);
        $this->assertSame([60], (new SendOutboundMail('x'))->backoff());

        config()->set('esign.mail.tries', 0);
        $this->assertSame(1, (new SendOutboundMail('x'))->tries());
    }

    private function runJobFor(OutboundMail $mail): void
    {
        (new SendOutboundMail($mail->public_id))->handle($this->app->make(OutboundMailSender::class));
    }

    /**
     * A sender whose transport always raises. Built by hand rather than by faking the mail
     * manager, because the point of the test is what the sender records when a real
     * transport-shaped exception comes back.
     */
    private function senderThatThrows(TransportException $exception): OutboundMailSender
    {
        $mailer = new class($exception) implements Mailer
        {
            public function __construct(private readonly TransportException $exception) {}

            public function to($users): never
            {
                throw $this->exception;
            }

            public function cc($users): never
            {
                throw $this->exception;
            }

            public function bcc($users): never
            {
                throw $this->exception;
            }

            public function raw($text, $callback): never
            {
                throw $this->exception;
            }

            public function send($view, array $data = [], $callback = null): never
            {
                throw $this->exception;
            }

            public function sendNow($mailable, array $data = [], $callback = null): never
            {
                throw $this->exception;
            }
        };

        $factory = new class($mailer) implements MailFactory
        {
            public function __construct(private readonly Mailer $mailer) {}

            public function mailer($name = null): Mailer
            {
                return $this->mailer;
            }
        };

        return new OutboundMailSender(
            $factory,
            $this->app->make(ProductionMailerGuard::class),
            new MailErrorRedactor,
            $this->app->make(RestoreDrill::class),
        );
    }
}
