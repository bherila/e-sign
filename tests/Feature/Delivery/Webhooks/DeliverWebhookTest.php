<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Webhooks;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\HostResolver;
use App\Domain\Delivery\Webhooks\DeliveryState;
use App\Domain\Delivery\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/**
 * What actually goes on the wire, and what happens when it does not arrive.
 *
 * The queue is faked and each attempt is run with `dispatch_sync`, so a retry
 * is queued and inspected rather than executed immediately — the sync driver
 * ignores delays, and letting it run would collapse the whole retry schedule
 * into one test tick.
 */
class DeliverWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_1111111111111111111111111111111111111111111111111111111111111111';

    private const PREVIOUS_SECRET = 'whsec_2222222222222222222222222222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_delivered_event_carries_a_signature_over_the_exact_bytes_sent(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);

        [$event, $endpoint, $delivery] = $this->queuedDelivery();

        $this->deliver($delivery);

        $request = $this->sentRequest();
        $body = $request->body();

        // The bytes signed are the bytes recorded on the event, unchanged.
        $this->assertSame($event->canonical_body, $body);

        [$timestamp, $signatures] = $this->parseSignature($request->header('X-Firma-Signature')[0]);

        // Verified the way a receiver would, from the scheme and not from our signer.
        $this->assertSame([hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)], $signatures);

        $delivery->refresh();
        $this->assertSame(DeliveryState::Succeeded, $delivery->state);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame((int) $timestamp, $delivery->signature_timestamp);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(0, $endpoint->refresh()->consecutive_failures);
    }

    public function test_the_signature_timestamp_is_fresh_at_the_moment_of_the_attempt(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $now = CarbonImmutable::parse('2026-09-08T09:00:00Z');
        $this->travelTo($now);

        [, , $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        [$timestamp] = $this->parseSignature($this->sentRequest()->header('X-Firma-Signature')[0]);

        $this->assertSame($now->getTimestamp(), (int) $timestamp);
    }

    public function test_the_identity_headers_name_the_event_the_attempt_and_the_type(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [$event, , $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $request = $this->sentRequest();

        $this->assertSame('signing_request.sent', $request->header('X-Firma-Event')[0]);
        $this->assertSame($delivery->public_id, $request->header('X-Firma-Delivery')[0]);
        $this->assertSame($event->public_id, $request->header('X-Esign-Event-Id')[0]);
        $this->assertSame('1', $request->header('X-Esign-Attempt')[0]);
        $this->assertSame('application/json', $request->header('Content-Type')[0]);
    }

    public function test_during_a_rotation_both_secrets_sign_the_same_attempt(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [, , $delivery] = $this->queuedDelivery(fn (WebhookEndpoint $endpoint) => $endpoint->forceFill([
            'secret_previous' => self::PREVIOUS_SECRET,
            'secret_previous_expires_at' => CarbonImmutable::now()->addHours(2),
        ])->save());

        $this->deliver($delivery);

        $request = $this->sentRequest();
        [$timestamp, $signatures] = $this->parseSignature($request->header('X-Firma-Signature')[0]);

        $this->assertSame([
            hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET),
            hash_hmac('sha256', $timestamp.'.'.$request->body(), self::PREVIOUS_SECRET),
        ], $signatures);

        // And the upstream-shaped header for a receiver that reads only that one.
        $this->assertSame(
            't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::PREVIOUS_SECRET),
            $request->header('X-Firma-Signature-Old')[0],
        );
    }

    public function test_an_expired_previous_secret_stops_signing(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [, , $delivery] = $this->queuedDelivery(fn (WebhookEndpoint $endpoint) => $endpoint->forceFill([
            'secret_previous' => self::PREVIOUS_SECRET,
            'secret_previous_expires_at' => CarbonImmutable::now()->subMinute(),
        ])->save());

        $this->deliver($delivery);

        $request = $this->sentRequest();
        [$timestamp, $signatures] = $this->parseSignature($request->header('X-Firma-Signature')[0]);

        $this->assertSame([hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET)], $signatures);
        $this->assertSame([], $request->header('X-Firma-Signature-Old'));
    }

    public function test_a_server_error_schedules_the_next_attempt_from_the_configured_schedule(): void
    {
        config()->set('esign.delivery.webhooks.retry_delays', [60, 300]);
        config()->set('esign.delivery.webhooks.retry_jitter', 0);
        Http::fake(['*' => Http::response('upstream exploded', 503)]);

        $now = CarbonImmutable::parse('2026-09-08T09:00:00Z');
        $this->travelTo($now);

        [$event, , $first] = $this->queuedDelivery();
        $this->deliver($first);

        $first->refresh();
        $this->assertSame(DeliveryState::Failed, $first->state);
        $this->assertSame(503, $first->response_status);
        $this->assertSame('upstream exploded', $first->response_excerpt);
        $this->assertTrue($now->addSeconds(60)->equalTo($first->next_attempt_at));

        $second = WebhookDelivery::query()->where('attempt', 2)->sole();
        $this->assertSame(DeliveryState::Pending, $second->state);
        $this->assertTrue($now->addSeconds(60)->equalTo($second->next_attempt_at));
        // The first attempt and its retry, each queued once.
        Queue::assertPushed(DeliverWebhook::class, 2);

        // The retry is the same event: a receiver deduplicating on the event id
        // sees what it already has, with a fresh signature over the same bytes.
        $this->travelTo($now->addSeconds(60));
        $this->deliver($second);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame($event->public_id, $requests[1][0]->header('X-Esign-Event-Id')[0]);
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertNotSame(
            $requests[0][0]->header('X-Firma-Signature')[0],
            $requests[1][0]->header('X-Firma-Signature')[0],
        );
        $this->assertNotSame(
            $requests[0][0]->header('X-Firma-Delivery')[0],
            $requests[1][0]->header('X-Firma-Delivery')[0],
        );
    }

    public function test_the_last_attempt_in_the_schedule_leaves_the_event_exhausted(): void
    {
        config()->set('esign.delivery.webhooks.retry_delays', [60]);
        config()->set('esign.delivery.webhooks.retry_jitter', 0);
        Http::fake(['*' => Http::response('', 500)]);

        [, $endpoint, $delivery] = $this->queuedDelivery();

        $this->deliver($delivery);
        $second = WebhookDelivery::query()->where('attempt', 2)->sole();
        $this->deliver($second);

        $this->assertSame(DeliveryState::Exhausted, $second->refresh()->state);
        $this->assertNull($second->next_attempt_at);
        $this->assertSame(2, WebhookDelivery::query()->count());
        $this->assertSame(1, $endpoint->refresh()->consecutive_failures);
    }

    public function test_a_client_error_is_not_retried_forever(): void
    {
        // A 403 says the request itself is wrong. Repeating it unchanged for two
        // days cannot help, so we stop and say so; replay is the way back.
        Http::fake(['*' => Http::response('signature rejected', 403)]);

        [, $endpoint, $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryState::Failed, $delivery->state);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertStringContainsString('not retryable', (string) $delivery->error);
        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertSame(1, $endpoint->refresh()->consecutive_failures);
    }

    public function test_the_two_client_errors_that_mean_later_are_retried(): void
    {
        Http::fake(['*' => Http::response('slow down', 429)]);

        [, , $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $this->assertSame(DeliveryState::Failed, $delivery->refresh()->state);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertSame(2, WebhookDelivery::query()->count());
    }

    public function test_a_timeout_or_a_lost_response_is_retried_and_recorded_without_a_status(): void
    {
        Http::fake(fn (): never => throw new ConnectionException(
            'cURL error 28: Operation timed out after 5000 milliseconds'
        ));

        [, , $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryState::Failed, $delivery->state);
        $this->assertNull($delivery->response_status);
        $this->assertStringContainsString('did not answer', (string) $delivery->error);
        $this->assertNotNull($delivery->next_attempt_at);
    }

    public function test_a_response_body_that_echoes_a_credential_is_redacted_before_it_is_stored(): void
    {
        Http::fake(['*' => Http::response('rejected Authorization: Bearer '.self::SECRET, 400)]);

        [, , $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $excerpt = (string) $delivery->refresh()->response_excerpt;

        $this->assertStringNotContainsString(self::SECRET, $excerpt);
        $this->assertStringContainsString('[redacted]', $excerpt);
    }

    public function test_a_long_response_body_is_truncated_to_the_configured_budget(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x y ', 2048), 500)]);

        [, , $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $this->assertLessThanOrEqual(1024, strlen((string) $delivery->refresh()->response_excerpt));
    }

    public function test_enough_undeliverable_events_disable_the_endpoint_with_a_visible_reason(): void
    {
        config()->set('esign.delivery.webhooks.auto_disable_after', 2);
        $this->refreshDispatcher();
        Http::fake(['*' => Http::response('gone', 410)]);

        $workspace = Workspace::factory()->create();
        $endpoint = $this->endpointFor($workspace);

        foreach (['signing_request.sent', 'signing_request.viewed'] as $eventName) {
            $delivery = $this->fanOut($this->recordEvent($workspace, $eventName))[0];
            $this->deliver($delivery);
        }

        $endpoint->refresh();
        $this->assertFalse($endpoint->isEnabled());
        $this->assertSame(2, $endpoint->consecutive_failures);
        $this->assertStringContainsString('Auto-disabled after 2', (string) $endpoint->disabled_reason);
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.auto_disabled']);
    }

    public function test_a_delivery_that_succeeds_clears_the_failure_streak(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [, $endpoint, $delivery] = $this->queuedDelivery(
            fn (WebhookEndpoint $endpoint) => $endpoint->forceFill(['consecutive_failures' => 3])->save()
        );

        $this->deliver($delivery);

        $this->assertSame(0, $endpoint->refresh()->consecutive_failures);
    }

    public function test_an_endpoint_disabled_after_the_attempt_was_queued_receives_nothing(): void
    {
        Http::fake();

        [, $endpoint, $delivery] = $this->queuedDelivery();
        $endpoint->forceFill(['disabled_at' => CarbonImmutable::now(), 'disabled_reason' => 'operator'])->save();

        $this->deliver($delivery);

        Http::assertNothingSent();
        $this->assertSame(DeliveryState::Failed, $delivery->refresh()->state);
        $this->assertStringContainsString('disabled', (string) $delivery->error);
    }

    public function test_an_already_settled_attempt_is_never_sent_twice(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [, , $delivery] = $this->queuedDelivery();

        $this->deliver($delivery);
        $this->deliver($delivery);

        $this->assertCount(1, Http::recorded());
    }

    public function test_a_destination_that_starts_resolving_internally_is_refused_at_send_time(): void
    {
        // DNS rebinding: the host was acceptable when the endpoint was created
        // and answers with an internal address now. The policy runs on every
        // attempt, so the request never leaves.
        Http::fake();
        $this->resolveWith(['rebind.example.test' => ['203.0.113.10']]);

        $workspace = Workspace::factory()->create();
        $endpoint = $this->endpointFor($workspace, ['url' => 'https://rebind.example.test/inbox']);
        $delivery = $this->fanOut($this->recordEvent($workspace))[0];

        $this->resolveWith(['rebind.example.test' => ['169.254.169.254']]);
        $this->deliver($delivery);

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame(DeliveryState::Failed, $delivery->state);
        $this->assertStringContainsString('non-public address', (string) $delivery->error);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertSame(1, $endpoint->refresh()->consecutive_failures);
    }

    public function test_a_replay_is_a_new_attempt_of_the_same_event(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [$event, $endpoint, $delivery] = $this->queuedDelivery();
        $this->deliver($delivery);

        $replayed = app(WebhookDispatcher::class)->replay($event, $endpoint)[0];
        $this->deliver($replayed);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame(2, $replayed->refresh()->attempt);
        $this->assertSame($event->public_id, $requests[1][0]->header('X-Esign-Event-Id')[0]);
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertNotSame(
            $requests[0][0]->header('X-Firma-Delivery')[0],
            $requests[1][0]->header('X-Firma-Delivery')[0],
        );
        $this->assertSame(1, OutboxEvent::query()->count());
    }

    /**
     * @return array{OutboxEvent, WebhookEndpoint, WebhookDelivery}
     */
    private function queuedDelivery(?callable $configureEndpoint = null): array
    {
        $workspace = Workspace::factory()->create();
        $endpoint = $this->endpointFor($workspace);

        if ($configureEndpoint !== null) {
            $configureEndpoint($endpoint);
        }

        $event = $this->recordEvent($workspace);

        return [$event, $endpoint, $this->fanOut($event)[0]];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function endpointFor(Workspace $workspace, array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::factory()->for($workspace)->create($attributes + [
            'secret_current' => self::SECRET,
        ]);
    }

    private function recordEvent(Workspace $workspace, string $eventName = 'signing_request.sent'): OutboxEvent
    {
        $event = new OutboxEvent([
            'workspace_id' => $workspace->getKey(),
            'event_name' => $eventName,
            'payload' => ['signing_request' => ['id' => 'sr_1']],
            'canonical_body' => '{"id":"'.($id = (string) Str::ulid()).'","type":"'.$eventName.'","data":{}}',
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $event->public_id = $id;
        $event->save();

        return $event;
    }

    /**
     * @return list<WebhookDelivery>
     */
    private function fanOut(OutboxEvent $event): array
    {
        return app(WebhookDispatcher::class)->fanOut($event);
    }

    /**
     * Run one attempt in-process.
     *
     * Not `dispatch_sync()`: with a faked queue that routes a ShouldQueue job
     * back to the fake instead of running it.
     */
    private function deliver(WebhookDelivery $delivery): void
    {
        $this->app->call([new DeliverWebhook($delivery->getKey()), 'handle']);
    }

    private function sentRequest(): Request
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'Expected a webhook request to have been sent.');

        return $recorded[0][0];
    }

    /**
     * @return array{string, list<string>} The `t=` value and every `v1=` value, in order.
     */
    private function parseSignature(string $header): array
    {
        $parts = explode(',', $header);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2);

            match ($key) {
                't' => $timestamp = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        $this->assertNotNull($timestamp, 'The signature header must carry a timestamp.');

        return [$timestamp, $signatures];
    }

    /**
     * @param  array<string, list<string>>  $answers
     */
    private function resolveWith(array $answers): void
    {
        $this->app->instance(HostResolver::class, new FakeHostResolver($answers));
        $this->app->forgetInstance(DestinationPolicy::class);
    }

    private function refreshDispatcher(): void
    {
        $this->app->forgetInstance(WebhookDispatcher::class);
    }
}
