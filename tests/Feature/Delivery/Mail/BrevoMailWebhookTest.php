<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\Models\OutboundMailEvent;
use App\Http\Requests\Mail\BrevoWebhookRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /webhooks/mail/brevo.
 *
 * The routes live in routes/mail-webhooks.php, which bootstrap/app.php does not yet
 * register — that file is owned by another change in flight. They are registered here so
 * the endpoints are covered now rather than after the wiring lands; see the PR description.
 *
 * Brevo signs nothing, so the shared token is the entire authentication story and gets the
 * most attention here. The rest is about not trusting the provider's ordering: webhooks
 * arrive late, twice, and out of sequence, and none of that may walk a message's state
 * backwards.
 */
class BrevoMailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'brevo-webhook-token-for-tests-only';

    protected function setUp(): void
    {
        parent::setUp();

        Route::group([], base_path('routes/mail-webhooks.php'));

        config()->set('esign.mail.brevo_webhook_token', self::TOKEN);
    }

    public function test_rejects_a_request_with_no_token(): void
    {
        $this->postJson('/webhooks/mail/brevo', $this->event('delivered', 'a@mail.example.test'))
            ->assertForbidden();

        $this->assertSame(0, OutboundMailEvent::query()->count());
    }

    public function test_rejects_a_request_with_the_wrong_token(): void
    {
        $this->withHeader(BrevoWebhookRequest::TOKEN_HEADER, 'not-the-token')
            ->postJson('/webhooks/mail/brevo', $this->event('delivered', 'a@mail.example.test'))
            ->assertForbidden();
    }

    public function test_rejects_a_token_that_is_a_prefix_of_the_configured_one(): void
    {
        // hash_equals, not a prefix or a loose comparison.
        $this->withHeader(BrevoWebhookRequest::TOKEN_HEADER, substr(self::TOKEN, 0, -1))
            ->postJson('/webhooks/mail/brevo', $this->event('delivered', 'a@mail.example.test'))
            ->assertForbidden();
    }

    public function test_an_unconfigured_token_disables_the_endpoint_rather_than_opening_it(): void
    {
        config()->set('esign.mail.brevo_webhook_token', '');

        $this->postJson('/webhooks/mail/brevo', $this->event('delivered', 'a@mail.example.test'))
            ->assertForbidden();

        // And an empty token presented against an empty configured token is still a refusal.
        $this->withHeader(BrevoWebhookRequest::TOKEN_HEADER, '')
            ->postJson('/webhooks/mail/brevo', $this->event('delivered', 'a@mail.example.test'))
            ->assertForbidden();
    }

    public function test_accepts_the_token_in_the_query_string_because_brevo_configures_a_url(): void
    {
        $mail = $this->sentMail('accepted@mail.example.test');

        $this->postJson('/webhooks/mail/brevo?token='.self::TOKEN, $this->event('delivered', 'accepted@mail.example.test'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'recorded' => 1]);

        $this->assertSame(MailState::Delivered, $mail->fresh()?->state);
    }

    /**
     * @return array<string, array{string, MailState|null}>
     */
    public static function providerEvents(): array
    {
        return [
            'delivered' => ['delivered', MailState::Delivered],
            'hardBounce' => ['hardBounce', MailState::Bounced],
            'softBounce' => ['softBounce', MailState::Bounced],
            'blocked' => ['blocked', MailState::Bounced],
            'error' => ['error', MailState::Bounced],
            'spam' => ['spam', MailState::Complained],
            // Still in flight: the receiving server asked for a retry.
            'deferred' => ['deferred', null],
            // An event name this release has never heard of moves nothing.
            'someFutureEvent' => ['someFutureEvent', null],
        ];
    }

    #[DataProvider('providerEvents')]
    public function test_maps_a_brevo_event_to_a_state(string $event, ?MailState $expected): void
    {
        $mail = $this->sentMail('mapped@mail.example.test');

        $this->deliver($this->event($event, 'mapped@mail.example.test'))->assertOk();

        $mail->refresh();

        $this->assertSame($expected ?? MailState::SentToProvider, $mail->state);

        $recorded = $mail->events()->where('source', MailEventSource::Brevo->value)->sole();
        $this->assertSame($event, $recorded->event);
        $this->assertSame($expected !== null, $recorded->payload['state_changed']);
    }

    public function test_matches_on_the_message_id_whatever_shape_brevo_sends_it_in(): void
    {
        $mail = $this->sentMail('shapes@mail.example.test');

        // Angle brackets and mixed case, which is how it arrives from an SMTP send.
        $this->deliver(['event' => 'delivered', 'message-id' => '<Shapes@Mail.Example.Test>'])
            ->assertOk();

        $this->assertSame(MailState::Delivered, $mail->fresh()?->state);
    }

    public function test_an_unknown_message_id_is_recorded_as_an_orphan_rather_than_dropped(): void
    {
        $mail = $this->sentMail('known@mail.example.test');

        $this->deliver($this->event('hardBounce', 'never-sent-from-here@mail.example.test'))
            ->assertOk()
            ->assertJson(['recorded' => 1]);

        $orphan = OutboundMailEvent::query()->whereNull('outbound_mail_id')->sole();

        $this->assertTrue($orphan->isOrphan());
        $this->assertSame('hardBounce', $orphan->event);
        $this->assertSame('never-sent-from-here@mail.example.test', $orphan->message_id);
        $this->assertTrue($orphan->payload['orphan']);

        // The message this deployment did send is untouched.
        $this->assertSame(MailState::SentToProvider, $mail->fresh()?->state);
    }

    public function test_a_late_delivered_does_not_undo_a_bounce(): void
    {
        $mail = $this->sentMail('ordering@mail.example.test');

        $this->deliver($this->event('hardBounce', 'ordering@mail.example.test'))->assertOk();
        $this->assertSame(MailState::Bounced, $mail->fresh()?->state);

        // Providers retry their own webhooks, so this ordering is normal rather than
        // pathological. A bounce an operator has already seen must not disappear.
        $this->deliver($this->event('delivered', 'ordering@mail.example.test'))->assertOk();
        $this->assertSame(MailState::Bounced, $mail->fresh()?->state);

        // Both claims are on the record even though only one moved the state.
        $this->assertCount(2, $mail->events()->where('source', MailEventSource::Brevo->value)->get());
    }

    public function test_a_duplicate_delivered_is_recorded_once_as_a_change(): void
    {
        $mail = $this->sentMail('duplicate@mail.example.test');

        $this->deliver($this->event('delivered', 'duplicate@mail.example.test'))->assertOk();
        $this->deliver($this->event('delivered', 'duplicate@mail.example.test'))->assertOk();

        $changed = $mail->events()
            ->where('source', MailEventSource::Brevo->value)
            ->get()
            ->filter(fn (OutboundMailEvent $event): bool => (bool) $event->payload['state_changed']);

        $this->assertCount(1, $changed);
        $this->assertSame(MailState::Delivered, $mail->fresh()?->state);
    }

    public function test_engagement_events_are_acknowledged_and_never_stored(): void
    {
        $mail = $this->sentMail('engagement@mail.example.test');

        $this->deliver([
            ['event' => 'opened', 'message-id' => 'engagement@mail.example.test'],
            ['event' => 'click', 'message-id' => 'engagement@mail.example.test', 'link' => 'https://esign.example.test/sign/x?t=y'],
        ])
            ->assertOk()
            ->assertJson(['recorded' => 0, 'ignored' => 2]);

        // No tracking pixel in the templates, and no open tracking through the provider's
        // side door either.
        $this->assertSame(0, $mail->events()->where('source', MailEventSource::Brevo->value)->count());
    }

    public function test_a_batch_records_every_event_it_can(): void
    {
        $first = $this->sentMail('first@mail.example.test');
        $second = $this->sentMail('second@mail.example.test');

        $this->deliver(['events' => [
            $this->event('delivered', 'first@mail.example.test'),
            $this->event('spam', 'second@mail.example.test'),
            // No event name at all. Dropped before validation, so the batch still
            // succeeds and Brevo does not redeliver the two good events forever.
            ['message-id' => 'first@mail.example.test'],
        ]])
            ->assertOk()
            ->assertJson(['recorded' => 2, 'ignored' => 0]);

        $this->assertSame(MailState::Delivered, $first->fresh()?->state);
        $this->assertSame(MailState::Complained, $second->fresh()?->state);
    }

    public function test_the_stored_payload_carries_no_address_or_token(): void
    {
        $mail = $this->sentMail('redaction@mail.example.test');

        $this->deliver([
            'event' => 'hardBounce',
            'message-id' => 'redaction@mail.example.test',
            'email' => 'avery@counterparty.test',
            'reason' => 'mailbox unavailable: avery@counterparty.test rejected',
            'link' => 'https://esign.example.test/sign/01JQZX?t=opaque-token-value',
        ])->assertOk();

        $encoded = json_encode($mail->events()->where('source', MailEventSource::Brevo->value)->sole()->payload);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('avery@counterparty.test', $encoded);
        $this->assertStringNotContainsString('opaque-token-value', $encoded);
        // The diagnosis survives redaction, which is the whole point of storing it.
        $this->assertStringContainsString('mailbox unavailable', $encoded);
    }

    public function test_rejects_a_body_that_is_not_a_recognizable_event(): void
    {
        $this->withHeader(BrevoWebhookRequest::TOKEN_HEADER, self::TOKEN)
            ->postJson('/webhooks/mail/brevo', ['nothing' => 'useful'])
            ->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $payload
     */
    private function deliver(array $payload): TestResponse
    {
        return $this->withHeader(BrevoWebhookRequest::TOKEN_HEADER, self::TOKEN)
            ->postJson('/webhooks/mail/brevo', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $name, string $messageId): array
    {
        return [
            'event' => $name,
            'message-id' => $messageId,
            'ts_event' => 1_767_225_600,
            'tag' => 'invitation',
        ];
    }

    private function sentMail(string $messageId): OutboundMail
    {
        return OutboundMail::factory()->sentToProvider($messageId)->create();
    }
}
