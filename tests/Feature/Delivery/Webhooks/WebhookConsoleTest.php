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
use App\Domain\Delivery\Webhooks\WebhookSecret;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

/**
 * The administrative surface. There is no HTTP administration for webhooks in
 * this change; these commands are it, and each one that changes state writes an
 * audit event.
 */
class WebhookConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_creating_an_endpoint_prints_the_secret_once_and_audits_the_change(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'acme']);

        $this->artisan('esign:webhook:endpoint:create', [
            'workspace' => 'acme',
            'url' => 'https://203.0.113.10/hooks',
            '--description' => 'Consumer inbox',
            '--event' => ['signing_request.completed'],
        ])
            ->expectsOutputToContain(WebhookSecret::PREFIX)
            ->expectsOutputToContain('only time the secret is shown')
            ->assertSuccessful();

        $endpoint = WebhookEndpoint::query()->sole();

        $this->assertSame($workspace->getKey(), $endpoint->workspace_id);
        $this->assertSame(['signing_request.completed'], $endpoint->event_filter);
        $this->assertStringStartsWith(WebhookSecret::PREFIX, $endpoint->secret_current);
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.created']);

        // Encrypted at rest: the plaintext is not in the column.
        $stored = (string) $this->getConnection()->table('webhook_endpoints')->value('secret_current');
        $this->assertStringNotContainsString($endpoint->secret_current, $stored);
    }

    public function test_an_endpoint_pointing_at_an_internal_address_is_refused_at_creation(): void
    {
        Workspace::factory()->create(['slug' => 'acme']);

        $this->artisan('esign:webhook:endpoint:create', [
            'workspace' => 'acme',
            'url' => 'https://169.254.169.254/latest/meta-data/',
        ])
            ->expectsOutputToContain('non-public address')
            ->assertFailed();

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_an_administrator_allowlist_entry_makes_one_internal_consumer_reachable(): void
    {
        config()->set('esign.delivery.destination_allowlist', [
            ['value' => 'consumer.internal.test', 'allow_private' => true, 'allow_plaintext' => true],
        ]);
        $this->app->instance(HostResolver::class, new FakeHostResolver([
            'consumer.internal.test' => ['10.8.0.9'],
        ]));
        $this->app->forgetInstance(DestinationPolicy::class);

        Workspace::factory()->create(['slug' => 'acme']);

        $this->artisan('esign:webhook:endpoint:create', [
            'workspace' => 'acme',
            'url' => 'http://consumer.internal.test:8080/hooks',
        ])->assertSuccessful();

        $this->assertSame(1, WebhookEndpoint::query()->count());
    }

    public function test_an_endpoint_filtered_to_an_unknown_event_is_refused(): void
    {
        Workspace::factory()->create(['slug' => 'acme']);

        $this->artisan('esign:webhook:endpoint:create', [
            'workspace' => 'acme',
            'url' => 'https://203.0.113.10/hooks',
            '--event' => ['signing_request.declined'],
        ])
            ->expectsOutputToContain('Unknown webhook event name')
            ->assertFailed();

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_rotating_a_secret_keeps_the_old_one_alive_for_the_grace_window(): void
    {
        $endpoint = WebhookEndpoint::factory()->create();
        $original = $endpoint->secret_current;

        $this->travelTo(CarbonImmutable::parse('2026-09-08T09:00:00Z'));

        $this->artisan('esign:webhook:endpoint:rotate-secret', [
            'endpoint' => $endpoint->public_id,
            '--grace-hours' => 24,
        ])->assertSuccessful();

        $endpoint->refresh();

        $this->assertNotSame($original, $endpoint->secret_current);
        $this->assertSame($original, $endpoint->secret_previous);
        $this->assertSame([$endpoint->secret_current, $original], $endpoint->signingSecrets());

        // One second past the window and the old secret stops signing.
        $this->assertSame(
            [$endpoint->secret_current],
            $endpoint->signingSecrets(CarbonImmutable::parse('2026-09-09T09:00:01Z')),
        );
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.secret_rotated']);
    }

    public function test_disabling_and_enabling_an_endpoint_is_visible_and_audited(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['consecutive_failures' => 4]);

        $this->artisan('esign:webhook:endpoint:disable', [
            'endpoint' => $endpoint->public_id,
            '--reason' => 'Receiver is being rebuilt',
        ])->assertSuccessful();

        $endpoint->refresh();
        $this->assertFalse($endpoint->isEnabled());
        $this->assertSame('Receiver is being rebuilt', $endpoint->disabled_reason);

        $this->artisan('esign:webhook:endpoint:list')
            ->expectsOutputToContain('Receiver is being rebuilt')
            ->assertSuccessful();

        $this->artisan('esign:webhook:endpoint:enable', ['endpoint' => $endpoint->public_id])
            ->assertSuccessful();

        $endpoint->refresh();
        $this->assertTrue($endpoint->isEnabled());
        $this->assertNull($endpoint->disabled_reason);
        // Re-enabling starts the failure count again, so the next auto-disable
        // counts failures since the operator said it was fixed.
        $this->assertSame(0, $endpoint->consecutive_failures);

        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.disabled']);
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.enabled']);
    }

    public function test_the_list_never_prints_a_secret(): void
    {
        WebhookEndpoint::factory()->create();

        $this->artisan('esign:webhook:endpoint:list')
            ->doesntExpectOutputToContain(WebhookSecret::PREFIX)
            ->assertSuccessful();
    }

    public function test_replaying_an_event_queues_a_fresh_attempt_and_audits_it(): void
    {
        $workspace = Workspace::factory()->create();
        $endpoint = WebhookEndpoint::factory()->for($workspace)->create();
        $event = $this->recordedEvent($workspace);

        $this->artisan('esign:webhook:replay', ['event' => $event->public_id])
            ->expectsOutputToContain('Queued 1 attempt(s)')
            ->assertSuccessful();

        $delivery = WebhookDelivery::query()->sole();

        $this->assertSame($endpoint->getKey(), $delivery->webhook_endpoint_id);
        $this->assertSame(1, $delivery->attempt);
        $this->assertSame(DeliveryState::Pending, $delivery->state);
        $this->assertSame(1, OutboxEvent::query()->count());
        Queue::assertPushed(DeliverWebhook::class, 1);
        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.event.replayed']);
    }

    public function test_replaying_to_a_disabled_endpoint_is_refused_with_the_way_to_fix_it(): void
    {
        $workspace = Workspace::factory()->create();
        $endpoint = WebhookEndpoint::factory()->for($workspace)->disabled('Receiver rejected everything')->create();
        $event = $this->recordedEvent($workspace);

        $this->artisan('esign:webhook:replay', [
            'event' => $event->public_id,
            '--endpoint' => $endpoint->public_id,
        ])
            ->expectsOutputToContain('Receiver rejected everything')
            ->assertFailed();

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_replaying_to_an_endpoint_in_another_workspace_is_refused(): void
    {
        $event = $this->recordedEvent(Workspace::factory()->create());
        $elsewhere = WebhookEndpoint::factory()->create();

        $this->artisan('esign:webhook:replay', [
            'event' => $event->public_id,
            '--endpoint' => $elsewhere->public_id,
        ])
            ->expectsOutputToContain('different workspace')
            ->assertFailed();
    }

    public function test_the_backlog_command_reports_overdue_work_and_disabled_endpoints(): void
    {
        $workspace = Workspace::factory()->create();
        $endpoint = WebhookEndpoint::factory()->for($workspace)->disabled()->create();
        $event = $this->recordedEvent($workspace);

        WebhookDelivery::create([
            'outbox_event_id' => $event->getKey(),
            'webhook_endpoint_id' => $endpoint->getKey(),
            'attempt' => 1,
            'state' => DeliveryState::Pending,
            'next_attempt_at' => CarbonImmutable::now()->subMinutes(9),
        ]);

        $this->artisan('esign:webhook:backlog')
            ->expectsOutputToContain('540s')
            ->expectsOutputToContain('1 endpoint(s) are disabled')
            ->assertSuccessful();
    }

    private function recordedEvent(Workspace $workspace): OutboxEvent
    {
        $event = new OutboxEvent([
            'workspace_id' => $workspace->getKey(),
            'event_name' => 'signing_request.completed',
            'payload' => [],
            'canonical_body' => '{"id":"'.($id = (string) Str::ulid()).'"}',
            'occurred_at' => CarbonImmutable::now(),
        ]);
        $event->public_id = $id;
        $event->save();

        return $event;
    }
}
