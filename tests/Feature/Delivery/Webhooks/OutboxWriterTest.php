<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery\Webhooks;

use App\Domain\Delivery\Webhooks\DeliveryState;
use App\Domain\Delivery\Webhooks\Exceptions\OutboxWriteOutsideTransactionException;
use App\Domain\Delivery\Webhooks\Exceptions\UnknownEventNameException;
use App\Domain\Delivery\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Delivery\Webhooks\Jobs\DispatchOutboxEvent;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Delivery\Webhooks\OutboxWriter;
use App\Domain\Delivery\Webhooks\WebhookDispatcher;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The transactional-outbox contract.
 *
 * DatabaseMigrations rather than RefreshDatabase on purpose: RefreshDatabase
 * wraps every test in a transaction, which would make `DB::transactionLevel()`
 * report 1 even where the test means to be outside one, and the central rule
 * here is precisely that a write outside a transaction is refused.
 */
class OutboxWriterTest extends TestCase
{
    use DatabaseMigrations;

    public function test_recording_an_event_outside_a_transaction_is_refused(): void
    {
        $workspace = Workspace::factory()->create();

        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->writer()->record($workspace, 'signing_request.sent', ['signing_request' => ['id' => 'x']]);
            $this->fail('An outbox write with no transaction open should be refused.');
        } catch (OutboxWriteOutsideTransactionException $refusal) {
            $this->assertStringContainsString('DB::transaction()', $refusal->getMessage());
        }

        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_a_recorded_event_carries_the_envelope_it_will_send(): void
    {
        $workspace = Workspace::factory()->create();
        $occurredAt = CarbonImmutable::parse('2026-09-08T10:11:12Z');

        $event = DB::transaction(fn (): OutboxEvent => $this->writer()->record(
            $workspace,
            'signing_request.completed',
            ['signing_request' => ['id' => 'sr_1'], 'recipients' => []],
            $occurredAt,
        ));

        $decoded = json_decode($event->canonical_body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($event->public_id, $decoded['id']);
        $this->assertSame('signing_request.completed', $decoded['type']);
        $this->assertSame('2026-09-08T10:11:12Z', $decoded['created_at']);
        $this->assertSame($workspace->public_id, $decoded['workspace_id']);
        $this->assertSame(['signing_request' => ['id' => 'sr_1'], 'recipients' => []], $decoded['data']);
        $this->assertTrue($occurredAt->equalTo($event->occurred_at));
    }

    public function test_a_rolled_back_transition_leaves_no_event_and_no_delivery(): void
    {
        Http::fake();
        $workspace = Workspace::factory()->create();
        WebhookEndpoint::factory()->for($workspace)->create();

        try {
            DB::transaction(function () use ($workspace): void {
                $this->writer()->record($workspace, 'signing_request.sent', ['signing_request' => ['id' => 'sr_1']]);

                throw new RuntimeException('the domain transition failed after the event was recorded');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, OutboxEvent::query()->count());
        $this->assertSame(0, WebhookDelivery::query()->count());
        Http::assertNothingSent();
    }

    public function test_fan_out_is_deferred_until_the_transaction_commits(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();

        DB::transaction(fn () => $this->writer()->record($workspace, 'signing_request.sent', []));

        Queue::assertPushed(
            DispatchOutboxEvent::class,
            fn (DispatchOutboxEvent $job): bool => $job->afterCommit === true,
        );
    }

    public function test_an_unknown_event_name_is_refused_rather_than_recorded(): void
    {
        $workspace = Workspace::factory()->create();

        $this->expectException(UnknownEventNameException::class);

        DB::transaction(fn () => $this->writer()->record($workspace, 'signing_request.compleded', []));
    }

    public function test_a_recorded_event_cannot_be_edited_afterwards(): void
    {
        $workspace = Workspace::factory()->create();
        $event = DB::transaction(fn (): OutboxEvent => $this->writer()->record($workspace, 'signing_request.sent', []));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/immutable/');

        $event->update(['event_name' => 'signing_request.completed']);
    }

    public function test_fan_out_reaches_every_enabled_endpoint_that_wants_the_event(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();

        $all = WebhookEndpoint::factory()->for($workspace)->create();
        $wanted = WebhookEndpoint::factory()->for($workspace)->filteredTo(['signing_request.sent'])->create();
        $other = WebhookEndpoint::factory()->for($workspace)->filteredTo(['signing_request.expired'])->create();
        $disabled = WebhookEndpoint::factory()->for($workspace)->disabled()->create();
        $elsewhere = WebhookEndpoint::factory()->create();

        $event = DB::transaction(fn () => $this->writer()->record($workspace, 'signing_request.sent', []));

        app(WebhookDispatcher::class)->fanOut($event);

        $recipients = WebhookDelivery::query()->pluck('webhook_endpoint_id')->all();

        $this->assertEqualsCanonicalizing([$all->getKey(), $wanted->getKey()], $recipients);
        $this->assertNotContains($other->getKey(), $recipients);
        $this->assertNotContains($disabled->getKey(), $recipients);
        $this->assertNotContains($elsewhere->getKey(), $recipients);

        $delivery = WebhookDelivery::query()->first();
        $this->assertSame(1, $delivery->attempt);
        $this->assertSame(DeliveryState::Pending, $delivery->state);
        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    public function test_two_events_keep_the_order_in_which_they_occurred(): void
    {
        // Delivery order is not guaranteed and a receiver must not infer it from
        // arrival. `occurred_at` is the fact that survives reordering.
        Queue::fake();
        $workspace = Workspace::factory()->create();

        $second = DB::transaction(fn () => $this->writer()->record(
            $workspace,
            'signing_request.completed',
            [],
            CarbonImmutable::parse('2026-09-08T12:00:05Z'),
        ));

        $first = DB::transaction(fn () => $this->writer()->record(
            $workspace,
            'signing_request.sent',
            [],
            CarbonImmutable::parse('2026-09-08T12:00:00Z'),
        ));

        $this->assertTrue($first->occurred_at->lessThan($second->occurred_at));
        $this->assertSame('2026-09-08T12:00:00Z', json_decode($first->canonical_body, true)['created_at']);
        $this->assertSame('2026-09-08T12:00:05Z', json_decode($second->canonical_body, true)['created_at']);
        $this->assertNotSame($first->public_id, $second->public_id);
    }

    private function writer(): OutboxWriter
    {
        return app(OutboxWriter::class);
    }
}
