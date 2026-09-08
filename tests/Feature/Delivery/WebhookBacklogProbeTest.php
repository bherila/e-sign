<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\WebhookBacklogProbe;
use App\Domain\Delivery\Webhooks\DeliveryState;
use App\Domain\Delivery\Webhooks\Models\OutboxEvent;
use App\Domain\Delivery\Webhooks\Models\WebhookDelivery;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookBacklogProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_outbox_is_ok(): void
    {
        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('No overdue deliveries.', $result->message);
    }

    public function test_a_retry_scheduled_for_later_is_not_a_backlog(): void
    {
        // The schedule working as designed must not read as a stalled queue.
        $this->pendingDelivery(CarbonImmutable::now()->addHours(12));

        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('1 scheduled', $result->message);
    }

    public function test_an_overdue_delivery_warns_and_then_fails_at_the_configured_thresholds(): void
    {
        config()->set('esign.delivery.webhooks.backlog_warn_seconds', 300);
        config()->set('esign.delivery.webhooks.backlog_fail_seconds', 1800);

        // Pin the clock: the message states the exact age, so a slow test host must not
        // turn "600s old" into "601s old".
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));

        $delivery = $this->pendingDelivery(CarbonImmutable::now()->subMinutes(10));

        $warned = $this->probe()->check();
        $this->assertSame(HealthStatus::Warn, $warned->status);
        $this->assertStringContainsString('600s old', $warned->message);

        $delivery->forceFill(['next_attempt_at' => CarbonImmutable::now()->subHour()])->save();

        $this->assertSame(HealthStatus::Fail, $this->probe()->check()->status);
    }

    public function test_a_disabled_endpoint_warns_without_making_the_instance_unready(): void
    {
        WebhookEndpoint::factory()->disabled()->create();

        $result = $this->probe()->check();

        $this->assertSame(HealthStatus::Warn, $result->status);
        $this->assertStringContainsString('1 endpoint(s) disabled', $result->message);
    }

    public function test_the_message_names_no_endpoint_and_no_url(): void
    {
        $endpoint = WebhookEndpoint::factory()->disabled()->create();
        $this->pendingDelivery(CarbonImmutable::now()->subHour(), $endpoint);

        $message = $this->probe()->check()->message;

        $this->assertStringNotContainsString($endpoint->url, $message);
        $this->assertStringNotContainsString($endpoint->public_id, $message);
    }

    private function probe(): WebhookBacklogProbe
    {
        return $this->app->make(WebhookBacklogProbe::class);
    }

    private function pendingDelivery(CarbonImmutable $dueAt, ?WebhookEndpoint $endpoint = null): WebhookDelivery
    {
        $workspace = Workspace::factory()->create();
        $endpoint ??= WebhookEndpoint::factory()->for($workspace)->create();

        $event = new OutboxEvent([
            'workspace_id' => $workspace->getKey(),
            'event_name' => 'signing_request.sent',
            'payload' => [],
            'canonical_body' => '{}',
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $event->public_id = (string) Str::ulid();
        $event->save();

        return WebhookDelivery::create([
            'outbox_event_id' => $event->getKey(),
            'webhook_endpoint_id' => $endpoint->getKey(),
            'attempt' => 1,
            'state' => DeliveryState::Pending,
            'next_attempt_at' => $dueAt,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
