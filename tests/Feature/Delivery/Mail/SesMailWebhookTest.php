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
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeHostResolver;
use Tests\Support\SyntheticSnsTopic;
use Tests\TestCase;

/**
 * POST /webhooks/mail/ses, with real SNS signature verification (issue #35).
 *
 * Every message here is signed for real, with a keypair and a self-signed certificate
 * generated in the process and served through a faked HTTP client. No AWS key material is
 * in this repository, nothing is fetched from AWS, and the code path exercised is the
 * production one: `Aws\Sns\MessageValidator` rebuilds AWS's canonical string-to-sign,
 * `SnsSigningCertificates` fetches the certificate through the shared destination policy and
 * caches it, and `openssl_verify` does the arithmetic.
 *
 * The synthetic topic builds its own string-to-sign from the AWS specification rather than
 * calling the library's, so a disagreement about field order or about which fields each
 * message type signs fails a test rather than passing quietly.
 */
class SesMailWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'arn:aws:sns:us-east-1:000000000000:esign-mail-feedback';

    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-synthetic.pem';

    private const SNS_HOST = 'sns.us-east-1.amazonaws.com';

    private SyntheticSnsTopic $aws;

    /** What the signing-certificate URL answers with. Mutable so a test can break it. */
    private string $certificateBody = '';

    /** What the SubscribeURL answers with. */
    private int $subscribeStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Route::group([], base_path('routes/mail-webhooks.php'));

        $this->aws = new SyntheticSnsTopic;

        config()->set('esign.mail.ses.topic_arns', [self::TOPIC]);

        // Every SNS timestamp in this class is relative to a frozen clock, so the replay
        // window is asserted against a stated age rather than against how long the suite
        // took to get here.
        Carbon::setTestNow(Carbon::parse('2026-09-08T10:00:00Z'));

        $this->certificateBody = $this->aws->certificatePem();
        $this->subscribeStatus = 200;

        $this->resolveSnsHostTo(['203.0.113.10']);
        $this->fakeAws();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Signature verification
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{'1'|'2'}>
     */
    public static function signatureVersions(): array
    {
        return [
            'v1 (SHA-1)' => ['1'],
            'v2 (SHA-256)' => ['2'],
        ];
    }

    #[DataProvider('signatureVersions')]
    public function test_a_correctly_signed_notification_is_accepted(string $version): void
    {
        // SHA-1 is refused by default; this asserts the mechanism works for both versions,
        // and the refusal is asserted separately below.
        config()->set('esign.mail.ses.allow_signature_version_1', true);

        $mail = OutboundMail::factory()->sentToProvider('0100019a-delivery')->create();

        $this->postSns($this->signed($this->notification('Delivery', '0100019a-delivery'), $version))
            ->assertOk()
            ->assertJson(['status' => 'recorded']);

        $this->assertSame(MailState::Delivered, $mail->refresh()->state);
    }

    public function test_signature_version_1_is_refused_unless_it_is_turned_on(): void
    {
        // SHA-1 has practical collisions. A signature over it is not evidence, so the
        // default is to refuse it and tell the operator to set the topic to version 2.
        $this->postSns($this->signed($this->notification('Delivery', 'anything'), '1'))
            ->assertStatus(503)
            ->assertJson(['status' => 'unverified']);

        $this->assertRefusedFor('signature_version_1_refused');
    }

    public function test_an_unsupported_signature_version_is_refused(): void
    {
        $envelope = $this->signed($this->notification('Delivery', 'anything'));
        $envelope['SignatureVersion'] = '3';

        $this->postSns($envelope)->assertStatus(503);

        $this->assertRefusedFor('signature_version_unsupported');
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $envelope = $this->signed($this->notification('Delivery', '0100019a-delivery'));

        // The signature is genuine; the body it covers is not the body that arrived. This
        // is the whole point of the endpoint: without it, anyone could post this.
        $envelope['Message'] = (string) json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => '0100019a-delivery'],
            'bounce' => ['bounceType' => 'Permanent'],
        ]);

        $this->postSns($envelope)->assertStatus(503);

        $this->assertRefusedFor('signature_invalid');
        $this->assertSame(0, OutboundMailEvent::query()->where('event', 'Bounce')->count());
    }

    public function test_a_signature_from_a_different_key_is_refused(): void
    {
        // Correct shape, correct topic, correct certificate URL — signed by somebody else.
        $impostor = new SyntheticSnsTopic;

        $this->postSns($impostor->sign($this->notification('Delivery', 'anything')))
            ->assertStatus(503);

        $this->assertRefusedFor('signature_invalid');
    }

    public function test_a_message_missing_a_signed_field_never_reaches_the_verifier(): void
    {
        $envelope = $this->signed($this->notification('Delivery', 'anything'));
        unset($envelope['Timestamp']);

        // 422, not 503: an envelope with no Timestamp is malformed rather than declined,
        // and saying so keeps the two distinguishable in an operator's logs.
        $this->postSns($envelope)->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // The signing certificate: where it may come from, and how often
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function hostileCertificateUrls(): array
    {
        return [
            'attacker host' => ['https://sns.us-east-1.amazonaws.com.attacker.test/cert.pem'],
            'plaintext' => ['http://sns.us-east-1.amazonaws.com/cert.pem'],
            'an s3 bucket on amazonaws.com' => ['https://bucket.s3.amazonaws.com/cert.pem'],
            'metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
            'not a pem' => ['https://sns.us-east-1.amazonaws.com/cert.txt'],
            'not a url' => ['nonsense'],
        ];
    }

    #[DataProvider('hostileCertificateUrls')]
    public function test_a_signing_certificate_url_that_is_not_aws_is_refused_without_fetching(string $url): void
    {
        $envelope = $this->notification('Delivery', 'anything');
        $envelope['SigningCertURL'] = $url;

        $this->postSns($this->signed($envelope))->assertStatus(503);

        // Nothing was fetched. An unauthenticated endpoint that chooses its own outbound
        // destination is a server-side request forgery primitive, and the URL is judged on
        // its spelling before any packet leaves.
        Http::assertNothingSent();
        $this->assertRefusedFor('certificate_url_not_aws');
    }

    public function test_a_certificate_url_on_an_aws_name_that_resolves_internally_is_refused(): void
    {
        // A legitimate AWS hostname whose DNS answer points inside the network. The name pin
        // cannot catch this; the shared destination policy is what does, and it decides
        // before the connection rather than after.
        $this->resolveSnsHostTo(['169.254.169.254']);

        $this->postSns($this->signed($this->notification('Delivery', 'anything')))->assertStatus(503);

        Http::assertNothingSent();
        $this->assertRefusedFor('certificate_destination_refused');
    }

    public function test_a_certificate_url_that_does_not_answer_with_a_certificate_is_refused(): void
    {
        $this->certificateBody = 'not a certificate';

        $this->postSns($this->signed($this->notification('Delivery', 'anything')))->assertStatus(503);

        $this->assertRefusedFor('certificate_malformed');
    }

    public function test_the_certificate_is_fetched_once_and_cached(): void
    {
        $mail = OutboundMail::factory()->sentToProvider('0100019a-delivery')->create();

        foreach (range(1, 3) as $ignored) {
            $this->postSns($this->signed($this->notification('Delivery', '0100019a-delivery')))->assertOk();
        }

        // Without the cache, a notification flood is also a certificate-fetch flood against
        // AWS, and the amplification factor is chosen by whoever is sending the flood.
        Http::assertSentCount(1);
        $this->assertSame(MailState::Delivered, $mail->refresh()->state);
    }

    // ------------------------------------------------------------------
    // Replay window
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function staleTimestamps(): array
    {
        return [
            'an hour old' => ['2026-09-08T09:00:00.000Z'],
            'an hour in the future' => ['2026-09-08T11:00:00.000Z'],
        ];
    }

    #[DataProvider('staleTimestamps')]
    public function test_a_message_outside_the_replay_window_is_refused(string $timestamp): void
    {
        // An SNS signature never expires, so without this a captured Bounce can be replayed
        // for as long as the certificate lives. Future-dated is refused too: that is not
        // clock skew, it is an edited envelope.
        $envelope = $this->notification('Delivery', 'anything');
        $envelope['Timestamp'] = $timestamp;

        $this->postSns($this->signed($envelope))->assertStatus(503);

        $this->assertRefusedFor('timestamp_outside_replay_window');
    }

    public function test_a_message_inside_the_replay_window_is_accepted(): void
    {
        $mail = OutboundMail::factory()->sentToProvider('0100019a-delivery')->create();

        $envelope = $this->notification('Delivery', '0100019a-delivery');
        $envelope['Timestamp'] = '2026-09-08T09:53:00.000Z';

        $this->postSns($this->signed($envelope))->assertOk();

        $this->assertSame(MailState::Delivered, $mail->refresh()->state);
    }

    // ------------------------------------------------------------------
    // The topic allowlist
    // ------------------------------------------------------------------

    public function test_a_validly_signed_message_from_an_unlisted_topic_is_refused(): void
    {
        // The signature is real. It proves the message came from SNS, not that it came from
        // *our* topic — anyone with an AWS account can sign one.
        $envelope = $this->notification('Delivery', 'anything');
        $envelope['TopicArn'] = 'arn:aws:sns:us-east-1:999999999999:someone-elses-topic';

        $this->postSns($this->signed($envelope))->assertForbidden();

        $this->assertRefusedFor('topic_not_allowlisted');
        Http::assertNothingSent();
    }

    public function test_the_verifier_refuses_an_unlisted_topic_even_reached_directly(): void
    {
        // The Form Request checks the ARN too, so this asserts the second layer on its own:
        // neither is load-bearing alone.
        $envelope = $this->signed($this->notification('Delivery', 'anything'));
        $envelope['TopicArn'] = 'arn:aws:sns:us-east-1:999999999999:someone-elses-topic';

        $this->expectException(SnsVerificationException::class);

        $this->app->make(SnsMessageVerifier::class)->verify($envelope);
    }

    public function test_an_unconfigured_deployment_fails_closed(): void
    {
        config()->set('esign.mail.ses.topic_arns', []);

        // Not "accepts anything with a valid signature". With no allowlist there is no
        // answer to "is this our topic?", so the container binds the rejecting verifier.
        $this->assertInstanceOf(
            RejectingSnsMessageVerifier::class,
            $this->app->make(SnsMessageVerifier::class),
        );

        $this->postSns($this->signed($this->notification('Delivery', 'anything')))->assertForbidden();

        $this->assertRefusedFor('no_topic_configured');
    }

    public function test_the_rejecting_verifier_refuses_everything(): void
    {
        $this->expectException(SnsVerificationException::class);

        (new RejectingSnsMessageVerifier)->verify(['Type' => 'Notification']);
    }

    // ------------------------------------------------------------------
    // Subscription confirmation
    // ------------------------------------------------------------------

    public function test_a_subscription_confirmation_is_not_confirmed_by_default(): void
    {
        $this->postSns($this->signed($this->subscriptionConfirmation()))
            ->assertOk()
            ->assertJson(['status' => 'not_confirmed']);

        // 200, not an error: the message was genuine and correctly addressed, and this
        // deployment has simply decided a person confirms the subscription. A non-2xx would
        // make SNS retry something declined on purpose.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'ConfirmSubscription'));
        $this->assertRefusedFor('auto_confirm_disabled');
    }

    public function test_a_subscription_confirmation_is_confirmed_when_it_is_turned_on(): void
    {
        config()->set('esign.mail.ses.auto_confirm_subscriptions', true);

        $envelope = $this->signed($this->subscriptionConfirmation());

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
        config()->set('esign.mail.ses.auto_confirm_subscriptions', true);

        $envelope = $this->subscriptionConfirmation();
        $envelope['SubscribeURL'] = $url;

        $this->postSns($this->signed($envelope))->assertStatus(422);

        // The certificate fetch is the only request that may have happened.
        Http::assertNotSent(fn ($request): bool => $request->url() === $url);
        $this->assertRefusedFor('subscribe_url_refused');
    }

    public function test_aws_being_unreachable_during_confirmation_is_a_retryable_503(): void
    {
        config()->set('esign.mail.ses.auto_confirm_subscriptions', true);

        $this->subscribeStatus = 503;

        // Not 422: the caller did nothing wrong, and SNS retries a 503, so a momentary blip
        // does not silently leave the topic unsubscribed.
        $this->postSns($this->signed($this->subscriptionConfirmation()))
            ->assertStatus(503)
            ->assertJson(['status' => 'unconfirmed']);
    }

    public function test_an_unsubscribe_confirmation_is_acknowledged_and_records_nothing(): void
    {
        $envelope = $this->subscriptionConfirmation();
        $envelope['Type'] = 'UnsubscribeConfirmation';

        $this->postSns($this->signed($envelope))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, OutboundMailEvent::query()->count());
    }

    // ------------------------------------------------------------------
    // Event mapping
    // ------------------------------------------------------------------

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
        $mail = OutboundMail::factory()->sentToProvider('0100019a-mapped')->create();

        $this->postSns($this->signed($this->notification($type, '0100019a-mapped')))
            ->assertOk()
            ->assertJson(['status' => 'recorded']);

        $mail->refresh();

        $this->assertSame($expected ?? MailState::SentToProvider, $mail->state);

        $event = $mail->events()->where('source', MailEventSource::Ses->value)->sole();
        $this->assertSame($type, $event->event);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bounceTypes(): array
    {
        return [
            'permanent' => ['Permanent', 'General'],
            'transient' => ['Transient', 'MailboxFull'],
        ];
    }

    #[DataProvider('bounceTypes')]
    public function test_a_bounce_records_its_classification_and_both_kinds_are_bounced(
        string $bounceType,
        string $subType,
    ): void {
        $mail = OutboundMail::factory()->sentToProvider('0100019a-bounce')->create();

        $envelope = $this->notification('Bounce', '0100019a-bounce');
        $envelope['Message'] = (string) json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => '0100019a-bounce', 'timestamp' => '2026-09-08T09:59:00.000Z'],
            'bounce' => [
                'bounceType' => $bounceType,
                'bounceSubType' => $subType,
                'timestamp' => '2026-09-08T09:59:30.000Z',
            ],
        ]);

        $this->postSns($this->signed($envelope))->assertOk();

        // Both are `bounced` — the state means "it did not arrive", which is true of each —
        // and the distinction survives on the payload, where it is worth something to an
        // operator deciding whether the address is worth retrying.
        $this->assertSame(MailState::Bounced, $mail->refresh()->state);

        $event = $mail->events()->where('source', MailEventSource::Ses->value)->sole();
        $this->assertSame($bounceType, $event->payload['bounce_type'] ?? null);
        $this->assertSame($subType, $event->payload['bounce_subtype'] ?? null);
    }

    public function test_an_event_is_stamped_with_its_own_time_not_the_send_time(): void
    {
        $mail = OutboundMail::factory()->sentToProvider('0100019a-stamped')->create();

        $envelope = $this->notification('Bounce', '0100019a-stamped');
        $envelope['Message'] = (string) json_encode([
            'notificationType' => 'Bounce',
            'mail' => [
                'messageId' => '0100019a-stamped',
                // When SES *sent* it. Identical on every notification about this message.
                'timestamp' => '2026-09-08T09:00:00.000Z',
            ],
            'bounce' => [
                'bounceType' => 'Permanent',
                // When the bounce actually happened, an hour later.
                'timestamp' => '2026-09-08T09:59:00.000Z',
            ],
        ]);

        $this->postSns($this->signed($envelope))->assertOk();

        $mail->refresh();
        $event = $mail->events()->where('source', MailEventSource::Ses->value)->sole();

        // Using the send time would stamp state_changed_at backwards and break both the
        // operator timeline and the 24-hour windows the backlog probe reads.
        $this->assertSame('2026-09-08 09:59:00', $event->occurred_at?->utc()->toDateTimeString());
        $this->assertSame('2026-09-08 09:59:00', $mail->state_changed_at->utc()->toDateTimeString());
    }

    public function test_a_verified_notification_for_an_unknown_message_is_an_orphan(): void
    {
        $this->postSns($this->signed($this->notification('Bounce', 'not-ours-0100019a')))->assertOk();

        $orphan = OutboundMailEvent::query()
            ->whereNull('outbound_mail_id')
            ->where('event', 'Bounce')
            ->sole();

        $this->assertSame(MailEventSource::Ses, $orphan->source);
        $this->assertSame('not-ours-0100019a', $orphan->message_id);
    }

    public function test_an_envelope_whose_message_is_not_ses_json_is_acknowledged_and_ignored(): void
    {
        $envelope = $this->notification('Delivery', 'anything');
        $envelope['Message'] = 'not json at all';

        // 200: a non-2xx would make SNS redeliver a body it can never make sense of.
        $this->postSns($this->signed($envelope))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, OutboundMailEvent::query()->count());
    }

    // ------------------------------------------------------------------
    // Matching feedback to a row
    // ------------------------------------------------------------------

    public function test_feedback_matches_the_ses_message_id_the_transport_recorded(): void
    {
        // SES reports its own id, not the RFC 5322 Message-ID, and this is the id the SES
        // transports hand back. See OutboundMailSender::providerMessageId().
        $mail = OutboundMail::factory()->sentToProvider('0100019a7f3c0001-a1b2c3d4')->create();

        $this->postSns($this->signed($this->notification('Delivery', '0100019a7f3c0001-a1b2c3d4')))->assertOk();

        $this->assertSame(MailState::Delivered, $mail->refresh()->state);
    }

    public function test_feedback_falls_back_to_the_rfc_5322_header_for_a_row_stored_under_it(): void
    {
        // Rows sent before the transport fix hold the RFC 5322 Message-ID. SES carries it in
        // `mail.headers` when the notification is configured to include original headers, so
        // those rows are still matchable rather than silently orphaned forever.
        $mail = OutboundMail::factory()->sentToProvider('legacy-id@mail.example.test')->create();

        $envelope = $this->notification('Delivery', '0100019a7f3c0001-unknown-to-us');
        $envelope['Message'] = (string) json_encode([
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => '0100019a7f3c0001-unknown-to-us',
                'timestamp' => '2026-09-08T09:59:00.000Z',
                'headers' => [
                    ['name' => 'Subject', 'value' => 'Please sign'],
                    ['name' => 'Message-ID', 'value' => '<legacy-id@mail.example.test>'],
                ],
            ],
            'delivery' => ['timestamp' => '2026-09-08T09:59:30.000Z'],
        ]);

        $this->postSns($this->signed($envelope))->assertOk();

        $this->assertSame(MailState::Delivered, $mail->refresh()->state);
        $this->assertSame(0, OutboundMailEvent::query()->whereNull('outbound_mail_id')->count());
    }

    // ------------------------------------------------------------------
    // What a refusal leaves behind
    // ------------------------------------------------------------------

    public function test_a_refusal_is_recorded_but_collapsed_within_its_window(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->postSns($this->signed($this->notification('Delivery', 'anything'), '1'))->assertStatus(503);
        }

        // Visible, but one row: this is an unauthenticated POST surface, and one row per
        // hostile request is a storage-growth primitive anyone on the internet can pull.
        $this->assertSame(
            1,
            OutboundMailEvent::query()->where('event', 'refused')->count(),
        );
    }

    public function test_a_refusal_logs_its_reason_and_nothing_else(): void
    {
        $log = Log::spy();

        $envelope = $this->signed($this->notification('Delivery', 'anything'), '1');
        $envelope['Subject'] = 'a subject that must not be logged';

        $this->postSns($envelope)->assertStatus(503);

        $log->shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context) use ($envelope): bool {
                $rendered = $message.' '.json_encode($context);

                return $context === ['reason' => 'signature_version_1_refused']
                    && ! str_contains($rendered, $envelope['Signature'])
                    && ! str_contains($rendered, $envelope['SigningCertURL'])
                    && ! str_contains($rendered, $envelope['Message'])
                    && ! str_contains($rendered, 'a subject that must not be logged');
            }
        );
    }

    public function test_a_refusal_row_carries_the_reason_and_the_claimed_topic_but_no_signature(): void
    {
        $envelope = $this->notification('Delivery', 'anything');
        $envelope['TopicArn'] = 'arn:aws:sns:us-east-1:999999999999:someone-elses-topic';
        $envelope = $this->signed($envelope);

        $this->postSns($envelope)->assertForbidden();

        $refusal = OutboundMailEvent::query()->where('event', 'refused')->sole();
        $payload = $refusal->payload ?? [];

        // The ARN is the one attacker-supplied value stored, because when the reason is
        // `topic_not_allowlisted` it is what tells an operator they mistyped their own.
        $this->assertSame('topic_not_allowlisted', $payload['reason'] ?? null);
        $this->assertSame($envelope['TopicArn'], $payload['claimed_topic_arn'] ?? null);
        $this->assertSame(json_encode($payload), str_replace($envelope['Signature'], 'X', (string) json_encode($payload)));
        $this->assertArrayNotHasKey('Message', $payload);
    }

    // ------------------------------------------------------------------
    // Transport shape
    // ------------------------------------------------------------------

    public function test_sns_posts_text_plain_and_the_envelope_is_still_read(): void
    {
        // SNS sets Content-Type: text/plain, so Laravel does not parse the body as JSON.
        $mail = OutboundMail::factory()->sentToProvider('0100019a-textplain')->create();

        $this->call(
            'POST',
            '/webhooks/mail/ses',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'],
            (string) json_encode($this->signed($this->notification('Delivery', '0100019a-textplain'))),
        )->assertOk();

        $this->assertSame(MailState::Delivered, $mail->refresh()->state);
    }

    public function test_an_unrecognized_envelope_type_is_rejected(): void
    {
        $envelope = $this->signed($this->notification('Delivery', 'anything'));
        $envelope['Type'] = 'SomethingElse';

        $this->postSns($envelope)->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertRefusedFor(string $reason): void
    {
        $refusals = OutboundMailEvent::query()->where('event', 'refused')->get();

        $this->assertContains(
            $reason,
            $refusals->map(fn (OutboundMailEvent $event): mixed => $event->payload['reason'] ?? null)->all(),
            'No refusal was recorded for "'.$reason.'". A refusal that leaves no trace is '
            .'indistinguishable from a provider that never called.',
        );
    }

    /**
     * One closure stub rather than a URL map, because `Http::fake()` *merges* stubs: a
     * second call in a test cannot replace what setUp registered, and the first matching
     * pattern wins. Reading the two mutable properties lets a test change what AWS says
     * without fighting that.
     */
    private function fakeAws(): void
    {
        Http::fake(fn (Request $request): PromiseInterface => str_contains($request->url(), 'SimpleNotificationService-')
            ? Http::response($this->certificateBody)
            : Http::response('', $this->subscribeStatus));
    }

    /**
     * @param  list<string>  $addresses
     */
    private function resolveSnsHostTo(array $addresses): void
    {
        $this->app->instance(DestinationPolicy::class, new DestinationPolicy(
            resolver: new FakeHostResolver([self::SNS_HOST => $addresses]),
        ));
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function signed(array $envelope, string $version = '2'): array
    {
        return $this->aws->sign($envelope, $version);
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
    private function subscriptionConfirmation(): array
    {
        return [
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => '11111111-2222-3333-4444-555555555555',
            'TopicArn' => self::TOPIC,
            'Timestamp' => '2026-09-08T09:59:59.000Z',
            'SigningCertURL' => self::CERT_URL,
            'Message' => 'You have chosen to subscribe to the topic '.self::TOPIC.'.',
            'Token' => 'synthetic-subscription-token',
            'SubscribeURL' => 'https://'.self::SNS_HOST.'/?Action=ConfirmSubscription&Token=synthetic',
        ];
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
            'Timestamp' => '2026-09-08T09:59:59.000Z',
            'SigningCertURL' => self::CERT_URL,
            'Message' => (string) json_encode([
                'notificationType' => $notificationType,
                'mail' => [
                    'messageId' => $messageId,
                    'timestamp' => '2026-09-08T09:59:00.000Z',
                    'destination' => ['avery@counterparty.test'],
                ],
                'bounce' => [
                    'bounceType' => 'Permanent',
                    'bounceSubType' => 'General',
                    'bouncedRecipients' => [['emailAddress' => 'avery@counterparty.test']],
                ],
            ]),
        ];
    }
}
