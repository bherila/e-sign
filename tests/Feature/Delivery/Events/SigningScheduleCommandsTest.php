<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Events;

use App\Domain\Delivery\Events\DeliveryEnvelopeEventSink;
use App\Domain\Delivery\Events\DownloadUrlMinter;
use App\Domain\Delivery\Events\ReminderScheduler;
use App\Domain\Delivery\Events\SigningUrlMinter;
use App\Domain\Delivery\Mail\Jobs\SendOutboundMail;
use App\Domain\Delivery\Mail\MailKind;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Signing\Contracts\AnchorResolution;
use App\Domain\Signing\Envelopes\EnvelopeState;
use App\Domain\Signing\Envelopes\EnvelopeStateMachine;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDownloadUrlMinter;
use Tests\Support\FakeSigningUrlMinter;
use Tests\Support\SigningScenario;
use Tests\TestCase;

/**
 * The two things only the clock can cause.
 *
 * Both commands are driven with time travel rather than by rewriting timestamps, because the
 * thresholds are the behaviour: a reminder that fires an hour early and an expiry that fires
 * a minute late are the failures worth catching, and neither shows up if the test moves the
 * rows instead of the clock.
 */
class SigningScheduleCommandsTest extends TestCase
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

        config([
            'esign.signing.reminder_after_hours' => 72,
            'esign.signing.reminder_interval_hours' => 24,
        ]);
    }

    public function test_nobody_is_reminded_before_the_configured_wait(): void
    {
        $this->sentEnvelope();

        $this->travel(71)->hours();
        $this->artisan('esign:signing:remind')->assertSuccessful();

        $this->assertSame([], $this->reminders());
    }

    public function test_a_recipient_still_being_waited_on_is_reminded_once_a_day(): void
    {
        $envelope = $this->sentEnvelope();

        $this->travel(73)->hours();
        $this->artisan('esign:signing:remind')->assertSuccessful();

        $this->assertSame(['buyer@example.test'], $this->reminders());

        // A second run in the same window is a no-op: the condition is a fact about the row,
        // not about how often cron fires.
        $this->artisan('esign:signing:remind')->assertSuccessful();
        $this->assertSame(['buyer@example.test'], $this->reminders());

        $this->travel(25)->hours();
        $this->artisan('esign:signing:remind')->assertSuccessful();
        $this->assertSame(['buyer@example.test', 'buyer@example.test'], $this->reminders());

        // The later stage has never been invited and is not eligible, so it is not nudged
        // about something it cannot act on.
        $this->assertNull($this->scenario->recipient($envelope, 'seller')->invited_at);
    }

    public function test_a_recipient_who_has_signed_is_not_reminded(): void
    {
        $envelope = $this->sentEnvelope();
        $buyer = $this->scenario->recipient($envelope, 'buyer');
        $this->scenario->completeRequiredFieldsFor($envelope->refresh(), $buyer);
        $this->machine()->accept($buyer->refresh(), $this->scenario->acceptanceRequest($envelope));

        $this->travel(80)->hours();
        $this->artisan('esign:signing:remind')->assertSuccessful();

        // Both were invited at the same moment and both are 80 hours past it. Only the one
        // the agreement is still waiting on is nudged: reminding somebody who has already
        // signed asks them for something they cannot give twice.
        $this->assertSame(['seller@example.test'], $this->reminders());
    }

    public function test_a_reminder_is_not_sent_for_an_envelope_that_has_already_expired(): void
    {
        $this->sentEnvelope(['expiration_hours' => 48]);

        $this->travel(80)->hours();
        $this->artisan('esign:signing:remind')->assertSuccessful();

        // Past its window, so the honest next step is the expiry command, not a nudge that
        // asks somebody to act on an agreement that is over.
        $this->assertSame([], $this->reminders());
    }

    public function test_the_expiry_command_closes_out_only_envelopes_that_are_due(): void
    {
        $due = $this->sentEnvelope(['expiration_hours' => 24]);
        $notDue = $this->sentEnvelope(['expiration_hours' => 500]);
        $eventFloor = (int) OutboxEvent::query()->max('id');
        $mailFloor = (int) OutboundMail::query()->max('id');

        $this->travel(25)->hours();
        $this->artisan('esign:signing:expire')->assertSuccessful();

        $this->assertSame(EnvelopeState::Expired, $due->refresh()->state);
        $this->assertSame(EnvelopeState::Sent, $notDue->refresh()->state);

        $this->assertSame(
            ['signing_request.expired'],
            OutboxEvent::query()->where('id', '>', $eventFloor)->pluck('event_name')->all(),
        );

        $this->assertEqualsCanonicalizing(
            ['buyer@example.test', $this->scenario->user->email],
            OutboundMail::query()
                ->where('kind', MailKind::Expired->value)
                ->where('id', '>', $mailFloor)
                ->pluck('to_email')
                ->all(),
        );
    }

    public function test_the_expiry_command_is_idempotent(): void
    {
        $this->sentEnvelope(['expiration_hours' => 24]);

        $this->travel(25)->hours();
        $this->artisan('esign:signing:expire')->assertSuccessful();

        $mailFloor = (int) OutboundMail::query()->max('id');

        $this->artisan('esign:signing:expire')->assertSuccessful();

        $this->assertSame(0, OutboundMail::query()->where('id', '>', $mailFloor)->count());
    }

    public function test_an_envelope_with_no_expiry_is_never_expired(): void
    {
        $envelope = $this->sentEnvelope(['expiration_hours' => null]);

        $this->assertNull($envelope->expires_at);

        $this->travel(400)->days();
        $this->artisan('esign:signing:expire')->assertSuccessful();

        $this->assertSame(EnvelopeState::Sent, $envelope->refresh()->state);
    }

    public function test_the_scheduler_reports_who_it_reminded(): void
    {
        $this->sentEnvelope();
        $this->travel(73)->hours();

        $reminded = $this->app->make(ReminderScheduler::class)->run();

        $this->assertCount(1, $reminded);
        $this->assertSame('buyer@example.test', $reminded[0]->email);
        $this->assertNotNull($reminded[0]->last_reminded_at);
    }

    /**
     * @return list<string>
     */
    private function reminders(): array
    {
        return OutboundMail::query()
            ->where('kind', MailKind::Reminder->value)
            ->orderBy('id')
            ->pluck('to_email')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sentEnvelope(array $overrides = []): Envelope
    {
        $envelope = $this->scenario->preparedDraft($overrides);
        $this->machine()->send($envelope);

        return $envelope->refresh();
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
