<?php

declare(strict_types=1);

namespace Tests\EndToEnd;

use App\Domain\Delivery\Webhooks\DeliveryState;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Delivery\Webhooks\WebhookEndpointManager;
use App\Domain\Delivery\Webhooks\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PdfFixtures;
use Tests\Support\SyntheticConsumer\ConsumerWebhookReceiver;
use Tests\Support\SyntheticConsumer\FirmaSignatureVerifier;
use Tests\Support\SyntheticConsumer\SyntheticConsumer;
use Tests\TestCase;

/**
 * Delivery to a receiver that is broken, slow, out of order, or halfway through a rotation.
 *
 * `tests/Feature/Delivery/Webhooks/DeliverWebhookTest.php` already covers each of these at the
 * transport, with a faked HTTP client and a hand-built event row. What this file adds is the
 * far end: a real receiver, on a real route, that verifies the signature itself and keeps its
 * own inbox — so "the retry carries a fresh timestamp" is asserted by something that would
 * reject the request if it did not, and "duplicate delivery is idempotent" is a property of
 * the consumer rather than a hope about it.
 *
 * **Replay protection inside the window belongs to the receiver**, and
 * `docs/delivery/webhooks.md` says so. Every idempotence assertion below is therefore an
 * assertion about {@see ConsumerWebhookReceiver}, which is
 * the consumer's code in this test — not a claim that the sender deduplicates.
 */
class WebhookReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNER = 'lee@reliability.example.test';

    public function test_a_receiver_that_fails_once_gets_a_retry_with_a_fresh_signature_and_the_same_event_id(): void
    {
        $consumer = SyntheticConsumer::install($this);

        // The receiver is down for exactly one request.
        $consumer->receiver->failNext(500);

        $this->sentEnvelope($consumer);
        $consumer->drainWebhooks();

        $firstAttempts = $consumer->receiver->attempts();
        $this->assertNotEmpty($firstAttempts);

        // A 5xx is retryable, so the schedule advanced rather than giving up.
        $this->assertGreaterThan(0, $consumer->scheduledRetries());

        $failed = WebhookDelivery::query()->where('response_status', 500)->sole();
        $this->assertSame(DeliveryState::Failed, $failed->state);
        $this->assertNotNull($failed->next_attempt_at);

        $rejectedAttempt = $firstAttempts[0];
        $this->assertTrue($rejectedAttempt->verified, 'A 500 must not be a signature problem.');

        // A minute later, the worker comes back for it.
        $this->travel(2)->minutes();
        $this->assertGreaterThan(0, $consumer->drainWebhooks());

        $retries = array_values(array_filter(
            $consumer->receiver->attempts($rejectedAttempt->type),
            static fn ($attempt): bool => $attempt->eventId === $rejectedAttempt->eventId,
        ));

        $this->assertCount(2, $retries);

        [$first, $second] = $retries;

        // The logical event is the same thing; the attempt is not.
        $this->assertSame($first->eventId, $second->eventId);
        $this->assertNotSame($first->attemptId, $second->attemptId);
        $this->assertSame(1, $first->attempt);
        $this->assertSame(2, $second->attempt);

        // A fresh timestamp, which is the only reason a retry two days later would still be
        // inside the receiver's five-minute window.
        $this->assertNotNull($second->signatureTimestamp);
        $this->assertGreaterThan((int) $first->signatureTimestamp, (int) $second->signatureTimestamp);
        $this->assertTrue($second->verified);

        // And the receiver processed it exactly once, on the attempt that got through.
        $this->assertSame(1, $consumer->receiver->countProcessed($first->type));
        $this->assertSame(0, $consumer->receiver->rejectedCount());
    }

    public function test_a_lost_response_is_retried_and_the_receiver_deduplicates_it(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $id = $this->sentEnvelope($consumer);
        $consumer->drainWebhooks();

        // The receiver processes the next request and the connection dies before the answer.
        // The sender cannot tell this from "never arrived", which is exactly why the event id
        // is stable and deduplication is the receiver's job.
        $consumer->nextDeliveryDrops(SyntheticConsumer::DROP_RESPONSE);

        $consumer->signAs($id, self::SIGNER);
        $consumer->drainWebhooks();

        $type = 'signing_request.recipient.signed';

        $lost = WebhookDelivery::query()->whereNull('response_status')->where('attempt', 1)->sole();
        $this->assertSame(DeliveryState::Failed, $lost->state);
        $this->assertStringContainsString('did not answer', (string) $lost->error);
        $this->assertNotNull($lost->next_attempt_at);

        $this->assertSame(1, $consumer->receiver->countProcessed($type), 'The receiver did process it.');

        $this->travel(2)->minutes();
        $consumer->drainWebhooks();

        $attempts = $consumer->receiver->attempts($type);
        $this->assertCount(2, $attempts);
        $this->assertSame($attempts[0]->eventId, $attempts[1]->eventId);

        // The duplicate is recognised, acknowledged with a 2xx, and not applied twice.
        $this->assertTrue($attempts[1]->duplicate);
        $this->assertSame(200, $attempts[1]->status);
        $this->assertSame(1, $consumer->receiver->countProcessed($type));
    }

    public function test_a_request_that_never_arrives_is_retried_until_it_does(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $id = $this->sentEnvelope($consumer);
        $consumer->drainWebhooks();

        $consumer->nextDeliveryDrops(SyntheticConsumer::DROP_REQUEST);

        $consumer->signAs($id, self::SIGNER);
        $consumer->drainWebhooks();

        $type = 'signing_request.recipient.signed';

        // Nothing reached the receiver at all.
        $this->assertSame([], $consumer->receiver->attempts($type));

        $this->travel(2)->minutes();
        $consumer->drainWebhooks();

        $retried = $consumer->receiver->attempts($type);
        $this->assertCount(1, $retried);
        $this->assertTrue($retried[0]->verified);
        $this->assertSame(2, $retried[0]->attempt);
        $this->assertSame(1, $consumer->receiver->countProcessed($type));
    }

    public function test_events_delivered_in_the_wrong_order_all_verify_and_the_inbox_does_not_regress(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $id = $this->sentEnvelope($consumer);

        // A later moment, so the three events describe three different instants. `created_at`
        // is when the transition happened, which is the only ordering that survives a retry.
        $this->travel(5)->seconds();
        $consumer->signAs($id, self::SIGNER);

        // Three events are now queued and none has been delivered. Hand them to the receiver
        // newest first: two events recorded seconds apart really can arrive reversed, and a
        // retry is all it takes.
        $this->assertSame(3, $consumer->drainWebhooks(reverse: true));

        $attempts = $consumer->receiver->attempts();
        $this->assertCount(3, $attempts);

        // Every one of them verified. Arrival order has nothing to do with the signature.
        foreach ($attempts as $attempt) {
            $this->assertTrue($attempt->verified, $attempt->type.' did not verify.');
            $this->assertSame(200, $attempt->status);
        }

        $this->assertSame([
            'signing_request.recipient.signed',
            'signing_request.sent',
            'signing_request.created',
        ], array_map(static fn ($a): string => $a->type, $attempts));

        // The inbox applied the newest and refused to regress to the older two, which is the
        // out-of-order protection `docs/HANDOFF.md` §11 asks a consumer for. Both were still
        // acknowledged: answering anything else asks for a redelivery of an event this inbox
        // has already dealt with.
        $this->assertSame(['signing_request.recipient.signed'], $consumer->receiver->processedTypes());
        $this->assertTrue($attempts[1]->regressive);
        $this->assertTrue($attempts[2]->regressive);

        // And `created_at` is what made that decision possible: it is when the transition
        // happened, not when the attempt was made.
        $this->assertGreaterThanOrEqual(strtotime($attempts[2]->occurredAt), strtotime($attempts[0]->occurredAt));
    }

    public function test_a_replay_is_a_new_attempt_of_the_same_event_and_the_receiver_ignores_it(): void
    {
        $consumer = SyntheticConsumer::install($this);

        $this->sentEnvelope($consumer);
        $consumer->drainWebhooks();

        $type = 'signing_request.sent';
        $event = OutboxEvent::query()->where('event_name', $type)->sole();

        $this->assertSame(1, $consumer->receiver->countProcessed($type));

        // What an administrator runs after fixing a receiver.
        app(WebhookDispatcher::class)->replay($event, $consumer->endpoint);
        $consumer->drainWebhooks();

        $attempts = $consumer->receiver->attempts($type);
        $this->assertCount(2, $attempts);

        $this->assertSame($attempts[0]->eventId, $attempts[1]->eventId);
        $this->assertSame($attempts[0]->rawBody, $attempts[1]->rawBody);
        $this->assertNotSame($attempts[0]->attemptId, $attempts[1]->attemptId);
        $this->assertTrue($attempts[1]->duplicate);

        // Which is what makes replay safe to hand an administrator.
        $this->assertSame(1, $consumer->receiver->countProcessed($type));
    }

    public function test_during_a_rotation_overlap_both_the_old_and_the_new_secret_verify(): void
    {
        $consumer = SyntheticConsumer::install($this);
        $old = $consumer->receiver->secrets()[0];

        $new = app(WebhookEndpointManager::class)->rotateSecret($consumer->endpoint->refresh(), graceHours: 168);
        $this->assertNotSame($old, $new);

        /* -------------------- a receiver that has not been reconfigured yet: old only */

        $consumer->receiver->acceptSecrets($old);

        $this->sentEnvelope($consumer);
        $consumer->drainWebhooks();

        $onOld = $consumer->receiver->attempts();
        $this->assertNotEmpty($onOld);

        foreach ($onOld as $attempt) {
            $this->assertTrue($attempt->verified, 'A receiver still on the old secret dropped an event.');
        }

        $this->assertSame(0, $consumer->receiver->rejectedCount());

        // The header itself: one `t=`, one `v1=` per live secret, current first — and the
        // upstream-shaped `X-Firma-Signature-Old` alongside for a receiver written literally
        // against the published guide.
        $wire = $consumer->lastSentRequest();
        $header = $wire->header(WebhookSigner::SIGNATURE_HEADER)[0];
        [$timestamp, $signatures] = FirmaSignatureVerifier::parse($header);

        $this->assertCount(2, $signatures);
        $this->assertNotNull($timestamp);

        $this->assertTrue(FirmaSignatureVerifier::verify($header, $wire->body(), [$new], (int) $timestamp));
        $this->assertTrue(FirmaSignatureVerifier::verify($header, $wire->body(), [$old], (int) $timestamp));

        $oldOnly = $wire->header(WebhookSigner::PREVIOUS_SIGNATURE_HEADER)[0];
        $this->assertTrue(FirmaSignatureVerifier::verify($oldOnly, $wire->body(), [$old], (int) $timestamp));
        $this->assertFalse(FirmaSignatureVerifier::verify($oldOnly, $wire->body(), [$new], (int) $timestamp));

        /* --------------------------- and the same run, after the receiver is switched */

        $consumer->receiver->acceptSecrets($new);

        $event = OutboxEvent::query()->where('event_name', 'signing_request.sent')->sole();
        app(WebhookDispatcher::class)->replay($event, $consumer->endpoint->refresh());
        $consumer->drainWebhooks();

        $afterSwitch = $consumer->receiver->attempts('signing_request.sent');
        $latest = $afterSwitch[count($afterSwitch) - 1];

        $this->assertTrue($latest->verified, 'A receiver moved to the new secret dropped an event.');
        $this->assertSame(0, $consumer->receiver->rejectedCount());
    }

    public function test_a_body_the_receiver_cannot_verify_is_a_four_hundred_and_is_never_retried(): void
    {
        $consumer = SyntheticConsumer::install($this);

        // The receiver has the wrong secret: an operator rotated and reconfigured backwards.
        $consumer->receiver->acceptSecrets('whsec_'.str_repeat('9', 58));

        $this->sentEnvelope($consumer);
        $consumer->drainWebhooks();

        $this->assertGreaterThan(0, $consumer->receiver->rejectedCount());
        $this->assertSame([], $consumer->receiver->processedTypes());

        // 400 rather than 500, so the sender stops instead of retrying something that can
        // never verify. Nothing is scheduled, and the endpoint's failure streak advances.
        $this->assertSame(0, $consumer->scheduledRetries());

        $refused = WebhookDelivery::query()->where('response_status', 400)->first();
        $this->assertNotNull($refused);
        $this->assertSame(DeliveryState::Failed, $refused->state);
        $this->assertNull($refused->next_attempt_at);
        $this->assertGreaterThan(0, $consumer->endpoint->refresh()->consecutive_failures);
    }

    /** A sent, single-signer agreement: the smallest thing that produces events. */
    private function sentEnvelope(SyntheticConsumer $consumer): string
    {
        return (string) $consumer->createAndSend([
            'name' => 'Synthetic delivery-reliability agreement',
            'document' => base64_encode(PdfFixtures::bytes('single-page-letter')),
            'settings' => ['require_otp_verification' => false],
            'recipients' => [
                ['first_name' => 'Lee', 'last_name' => 'Signer', 'email' => self::SIGNER, 'designation' => 'Signer', 'order' => 1],
            ],
            'fields' => [
                ['type' => 'signature', 'page_number' => 1, 'variable_name' => 'Signature', 'recipient_email' => self::SIGNER, 'required' => true, 'position' => ['x' => 10.0, 'y' => 72.0, 'width' => 30.0, 'height' => 5.0]],
            ],
        ])->assertStatus(201)->json('id');
    }
}
