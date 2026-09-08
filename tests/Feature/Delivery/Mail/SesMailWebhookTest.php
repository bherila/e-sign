<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Mail;

use App\Domain\Delivery\Mail\Feedback\RejectingSnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SnsMessageVerifier;
use App\Domain\Delivery\Mail\Feedback\SnsVerificationException;
use App\Domain\Delivery\Mail\MailEventSource;
use App\Domain\Delivery\Mail\MailState;
use App\Domain\Delivery\Mail\Models\OutboundMail;
use App\Domain\Delivery\Mail\Models\OutboundMailEvent;
use App\Domain\Delivery\Outbound\DestinationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/**
 * POST /webhooks/mail/ses.
 *
 * The endpoint fails closed. `aws/aws-sns-message-validator` is not a dependency — the AWS
 * SDK is present only transitively, through league/flysystem-aws-s3-v3, and does not include
 * the validator — so no SNS signature can be checked and every message is refused. These
 * tests fix that as the intended behaviour rather than an accident, so a later change that
 * quietly starts accepting unsigned input fails here.
 *
 * The path behind the verifier is exercised by binding a stub verifier. That is not a
 * loophole in the product: it is how the topic check, the Delivery/Bounce/Complaint mapping,
 * and the subscription-confirmation guard get to be reviewed and covered before a real
 * verifier exists.
 *
 * Like the Brevo tests, this registers routes/mail-webhooks.php itself; bootstrap/app.php
 * does not yet do so.
 */
class SesMailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'arn:aws:sns:us-east-1:000000000000:esign-mail-feedback';

    protected function setUp(): void
    {
        parent::setUp();

        Route::group([], base_path('routes/mail-webhooks.php'));

        config()->set('esign.mail.ses_topic_arn', self::TOPIC);
    }

    public function test_the_shipped_verifier_refuses_everything(): void
    {
        $this->expectException(SnsVerificationException::class);

        (new RejectingSnsMessageVerifier)->verify(['Type' => 'Notification']);
    }

    public function test_a_signed_looking_message_from_the_right_topic_is_still_refused(): void
    {
        // Nothing about this request is wrong. It is refused because this deployment cannot
        // prove where it came from, which is the only honest answer.
        $this->postSns($this->notification('Delivery', 'ses@mail.example.test'))
            ->assertStatus(503)
            ->assertJson(['status' => 'unverified']);

        $this->assertSame(0, OutboundMailEvent::query()->count());
    }

    public function test_an_unknown_topic_is_rejected(): void
    {
        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['TopicArn'] = 'arn:aws:sns:us-east-1:999999999999:someone-elses-topic';

        $this->postSns($envelope)->assertForbidden();
    }

    public function test_an_unconfigured_topic_disables_the_endpoint(): void
    {
        config()->set('esign.mail.ses_topic_arn', '');

        $this->postSns($this->notification('Delivery', 'ses@mail.example.test'))->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsignedFields(): array
    {
        return [
            'no signature' => ['Signature'],
            'no signature version' => ['SignatureVersion'],
            'no signing certificate url' => ['SigningCertURL'],
        ];
    }

    #[DataProvider('unsignedFields')]
    public function test_an_unsigned_message_is_rejected_before_it_reaches_the_verifier(string $missing): void
    {
        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        unset($envelope[$missing]);

        // 422 rather than 503: a body with no signature fields is malformed, and saying so
        // keeps it distinguishable from a well-formed message this deployment declines.
        $this->postSns($envelope)->assertStatus(422);
    }

    public function test_an_unrecognized_envelope_type_is_rejected(): void
    {
        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Type'] = 'SomethingElse';

        $this->postSns($envelope)->assertStatus(422);
    }

    public function test_sns_posts_text_plain_and_the_envelope_is_still_read(): void
    {
        // SNS sets Content-Type: text/plain, so Laravel does not parse the body as JSON.
        // Reaching a 503 (rather than a 422) proves the envelope was decoded and the topic
        // matched.
        $this->call(
            'POST',
            '/webhooks/mail/ses',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            json_encode($this->notification('Delivery', 'ses@mail.example.test')) ?: '',
        )->assertStatus(503);
    }

    /**
     * @return array<string, array{string, MailState|null}>
     */
    public static function sesNotificationTypes(): array
    {
        return [
            'Delivery' => ['Delivery', MailState::Delivered],
            'Bounce' => ['Bounce', MailState::Bounced],
            'Complaint' => ['Complaint', MailState::Complained],
            'Send' => ['Send', MailState::Accepted],
            'Reject' => ['Reject', MailState::Bounced],
            // Temporary; SES is still trying.
            'DeliveryDelay' => ['DeliveryDelay', null],
        ];
    }

    #[DataProvider('sesNotificationTypes')]
    public function test_maps_a_verified_notification_to_a_state(string $type, ?MailState $expected): void
    {
        $this->withStubVerifier();

        $mail = OutboundMail::factory()->sentToProvider('ses@mail.example.test')->create();

        $this->postSns($this->notification($type, 'ses@mail.example.test'))
            ->assertOk()
            ->assertJson(['status' => 'recorded']);

        $mail->refresh();

        $this->assertSame($expected ?? MailState::SentToProvider, $mail->state);

        $event = $mail->events()->where('source', MailEventSource::Ses->value)->sole();
        $this->assertSame($type, $event->event);
    }

    public function test_an_event_is_stamped_with_its_own_time_not_the_send_time(): void
    {
        $this->withStubVerifier();

        $mail = OutboundMail::factory()->sentToProvider('ses@mail.example.test')->create();

        $envelope = $this->notification('Bounce', 'ses@mail.example.test');
        $envelope['Message'] = json_encode([
            'notificationType' => 'Bounce',
            'mail' => [
                'messageId' => 'ses@mail.example.test',
                // When SES *sent* it. Identical on every notification about this message.
                'timestamp' => '2026-09-08T09:00:00.000Z',
            ],
            'bounce' => [
                'bounceType' => 'Permanent',
                // When the bounce actually happened, an hour later.
                'timestamp' => '2026-09-08T10:00:00.000Z',
            ],
        ]);

        $this->postSns($envelope)->assertOk();

        $mail->refresh();
        $event = $mail->events()->where('source', MailEventSource::Ses->value)->sole();

        // Using the send time would stamp state_changed_at backwards and break both the
        // operator timeline and the 24-hour windows the backlog probe reads.
        $this->assertSame('2026-09-08 10:00:00', $event->occurred_at?->utc()->toDateTimeString());
        $this->assertSame('2026-09-08 10:00:00', $mail->state_changed_at->utc()->toDateTimeString());
    }

    public function test_the_send_time_is_the_fallback_when_the_event_carries_none(): void
    {
        $this->withStubVerifier();

        OutboundMail::factory()->sentToProvider('ses@mail.example.test')->create();

        $this->postSns($this->notification('Delivery', 'ses@mail.example.test'))->assertOk();

        // A timestamp near the truth beats none.
        $this->assertSame(
            '2026-09-08 09:59:59',
            OutboundMailEvent::query()->sole()->occurred_at?->utc()->toDateTimeString(),
        );
    }

    public function test_the_envelope_keeps_the_fields_a_real_verifier_needs(): void
    {
        $seen = null;

        $this->app->instance(SnsMessageVerifier::class, new class($seen) implements SnsMessageVerifier
        {
            public function __construct(public mixed &$seen) {}

            public function verify(array $envelope): void
            {
                $this->seen = $envelope;
            }
        });

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Token'] = 'synthetic-subscription-token';
        $envelope['Subject'] = 'Amazon SES Email Event Notification';

        $this->postSns($envelope)->assertOk();

        // `Token` confirms a subscription and `Subject` enters the string-to-sign, so
        // dropping either would make a genuine verifier fail the day SES is turned on.
        $this->assertSame('synthetic-subscription-token', $seen['Token'] ?? null);
        $this->assertSame('Amazon SES Email Event Notification', $seen['Subject'] ?? null);
        $this->assertSame(self::TOPIC, $seen['TopicArn'] ?? null);
        $this->assertArrayHasKey('Signature', $seen);
        $this->assertArrayHasKey('SigningCertURL', $seen);
    }

    public function test_aws_being_unreachable_is_a_retryable_503_rather_than_a_refusal(): void
    {
        $this->withStubVerifier();
        $this->withResolvedSnsHost(['203.0.113.10']);
        Http::fake(['sns.us-east-1.amazonaws.com/*' => Http::response('', 503)]);

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Type'] = 'SubscriptionConfirmation';
        $envelope['SubscribeURL'] = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription';

        // Not 422: the caller did nothing wrong, and SNS retries a 503, so a momentary
        // blip does not silently leave the topic unsubscribed.
        $this->postSns($envelope)->assertStatus(503)->assertJson(['status' => 'unconfirmed']);
    }

    public function test_a_verified_notification_for_an_unknown_message_is_an_orphan(): void
    {
        $this->withStubVerifier();

        $this->postSns($this->notification('Bounce', 'not-ours@mail.example.test'))->assertOk();

        $orphan = OutboundMailEvent::query()->whereNull('outbound_mail_id')->sole();

        $this->assertSame(MailEventSource::Ses, $orphan->source);
        $this->assertSame('not-ours@mail.example.test', $orphan->message_id);
    }

    public function test_an_envelope_whose_message_is_not_ses_json_is_acknowledged_and_ignored(): void
    {
        $this->withStubVerifier();

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Message'] = 'not json at all';

        // 200: a non-2xx would make SNS redeliver a body it can never make sense of.
        $this->postSns($envelope)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, OutboundMailEvent::query()->count());
    }

    public function test_a_subscription_confirmation_is_fetched_from_aws_only(): void
    {
        $this->withStubVerifier();
        $this->withResolvedSnsHost(['203.0.113.10']);
        Http::fake();

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Type'] = 'SubscriptionConfirmation';
        $envelope['SubscribeURL'] = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription';

        $this->postSns($envelope)->assertOk()->assertJson(['status' => 'confirmed']);

        Http::assertSent(fn ($request): bool => $request->url() === $envelope['SubscribeURL']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileSubscribeUrls(): array
    {
        return [
            'attacker host' => ['https://sns.us-east-1.amazonaws.com.attacker.test/confirm'],
            'internal host' => ['https://127.0.0.1/confirm'],
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
            'plaintext aws' => ['http://sns.us-east-1.amazonaws.com/confirm'],
            'not a url' => ['nonsense'],
        ];
    }

    #[DataProvider('hostileSubscribeUrls')]
    public function test_a_subscription_confirmation_url_that_is_not_aws_is_refused(string $url): void
    {
        $this->withStubVerifier();
        $this->withResolvedSnsHost(['203.0.113.10']);
        Http::fake();

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Type'] = 'SubscriptionConfirmation';
        $envelope['SubscribeURL'] = $url;

        $this->postSns($envelope)->assertStatus(422);

        // Nothing was fetched: an unauthenticated webhook that chooses its own outbound
        // destination is a server-side request forgery primitive.
        Http::assertNothingSent();
    }

    public function test_a_confirmation_url_on_an_aws_name_that_resolves_internally_is_refused(): void
    {
        $this->withStubVerifier();
        // A legitimate AWS hostname whose DNS answer points inside the network. The host
        // pin cannot catch this; the shared destination policy is what does.
        $this->withResolvedSnsHost(['169.254.169.254']);
        Http::fake();

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Type'] = 'SubscriptionConfirmation';
        $envelope['SubscribeURL'] = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription';

        $this->postSns($envelope)->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_unsubscribe_confirmation_is_acknowledged_and_records_nothing(): void
    {
        $this->withStubVerifier();

        $envelope = $this->notification('Delivery', 'ses@mail.example.test');
        $envelope['Type'] = 'UnsubscribeConfirmation';

        $this->postSns($envelope)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, OutboundMailEvent::query()->count());
    }

    /**
     * A verifier that accepts, standing in for the real one that does not exist yet.
     */
    private function withStubVerifier(): void
    {
        $this->app->instance(SnsMessageVerifier::class, new class implements SnsMessageVerifier
        {
            public function verify(array $envelope): void
            {
                //
            }
        });
    }

    /**
     * @param  list<string>  $addresses
     */
    private function withResolvedSnsHost(array $addresses): void
    {
        $this->app->instance(DestinationPolicy::class, new DestinationPolicy(
            resolver: new FakeHostResolver(['sns.us-east-1.amazonaws.com' => $addresses]),
            subject: 'SNS subscription confirmation',
        ));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function postSns(array $envelope): TestResponse
    {
        return $this->postJson('/webhooks/mail/ses', $envelope);
    }

    /**
     * @return array<string, mixed>
     */
    private function notification(string $notificationType, string $messageId): array
    {
        return [
            'Type' => 'Notification',
            'MessageId' => '11111111-2222-3333-4444-555555555555',
            'TopicArn' => self::TOPIC,
            'Timestamp' => '2026-09-08T10:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'c3ludGhldGljLXNpZ25hdHVyZQ==',
            'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-synthetic.pem',
            'Message' => json_encode([
                'notificationType' => $notificationType,
                'mail' => [
                    'messageId' => $messageId,
                    'timestamp' => '2026-09-08T09:59:59.000Z',
                    'destination' => ['avery@counterparty.test'],
                ],
                'bounce' => [
                    'bounceType' => 'Permanent',
                    'bouncedRecipients' => [['emailAddress' => 'avery@counterparty.test']],
                ],
            ]),
        ];
    }
}
