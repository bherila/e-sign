<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\HostResolver;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Credentials\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeHostResolver;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * Webhook endpoint administration under `webhooks:manage`.
 *
 * Two properties matter more than the CRUD: a secret is shown exactly once and never
 * returned again, and every write leaves an audit event naming the credential that made it.
 */
class NativeApiWebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The endpoint manager validates a destination against the outbound policy before it
        // stores it, which means resolving the host. A test that depended on what the machine
        // running it can resolve is a test that fails on somebody else's laptop, so the
        // resolver is a fixed answer table (the same one the webhook suite uses).
        $this->app->instance(HostResolver::class, new FakeHostResolver([
            'receiver.example.test' => ['198.51.100.20'],
            'moved.example.test' => ['198.51.100.21'],
        ]));
        $this->app->forgetInstance(DestinationPolicy::class);
    }

    public function test_an_endpoint_is_created_and_its_secret_is_shown_once(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);

        $response = $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
            'description' => 'Synthetic receiver',
            'event_filter' => ['signing_request.completed'],
        ], NativeApiScenario::headers($issued));

        $response->assertCreated()
            ->assertJsonPath('url', 'https://receiver.example.test/hooks/esign')
            ->assertJsonPath('description', 'Synthetic receiver')
            ->assertJsonPath('event_filter', ['signing_request.completed'])
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('consecutive_failures', 0);

        $secret = $response->json('secret');
        $this->assertIsString($secret);
        $this->assertNotSame('', $secret);

        // Shown once and never again: only ciphertext is stored, and no route reads it back.
        $listed = $this->getJson('/api/v1/webhooks/endpoints', NativeApiScenario::headers($issued))->assertOk();
        $this->assertStringNotContainsString($secret, (string) $listed->getContent());
        $listed->assertJsonMissingPath('data.0.secret');

        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.created']);
        $this->assertStringContainsString(
            $issued->credential->prefix,
            (string) AuditEvent::query()->where('action', 'webhook.endpoint.created')->firstOrFail()->actor_label,
        );
    }

    public function test_an_unknown_event_name_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);

        $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
            'event_filter' => ['signing_request.compleded'],
        ], NativeApiScenario::headers($issued))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_event_name');

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_an_empty_event_filter_is_refused(): void
    {
        $scenario = NativeApiScenario::create();

        $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
            'event_filter' => [],
        ], NativeApiScenario::headers($scenario->credential([Scope::WebhooksManage])))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_only_the_keys_present_in_a_patch_are_applied(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);
        $endpoint = $this->endpoint($scenario, $issued);

        $this->patchJson('/api/v1/webhooks/endpoints/'.$endpoint, ['description' => 'Renamed receiver'],
            NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('description', 'Renamed receiver')
            // Omitting the filter left it alone rather than blanking it.
            ->assertJsonPath('event_filter', ['signing_request.completed']);

        // Null means every event, which is different from omitting it.
        $this->patchJson('/api/v1/webhooks/endpoints/'.$endpoint, ['event_filter' => null],
            NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('event_filter', null);
    }

    public function test_an_endpoint_can_be_paused_and_re_enabled(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);
        $endpoint = $this->endpoint($scenario, $issued);

        WebhookEndpoint::query()->where('public_id', $endpoint)->update(['consecutive_failures' => 9]);

        $this->patchJson('/api/v1/webhooks/endpoints/'.$endpoint, ['enabled' => false],
            NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('disabled_reason', 'Disabled through the native API.');

        $this->patchJson('/api/v1/webhooks/endpoints/'.$endpoint, ['enabled' => true],
            NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('enabled', true)
            // Re-enabling clears the streak, so the next auto-disable counts from now.
            ->assertJsonPath('consecutive_failures', 0);
    }

    public function test_delete_retires_the_endpoint_and_keeps_the_row(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);
        $endpoint = $this->endpoint($scenario, $issued);

        $this->deleteJson('/api/v1/webhooks/endpoints/'.$endpoint, [], NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('disabled_reason', 'Retired through the native API.');

        // The row survives because delivery history references it. A `204` would have
        // implied an erasure that does not happen.
        $this->assertDatabaseHas('webhook_endpoints', ['public_id' => $endpoint]);
    }

    public function test_rotating_a_secret_returns_a_new_one_and_opens_an_overlap(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);
        $endpoint = $this->endpoint($scenario, $issued);

        $rotated = $this->postJson('/api/v1/webhooks/endpoints/'.$endpoint.'/rotate-secret',
            ['grace_hours' => 24], NativeApiScenario::headers($issued))->assertOk();

        $this->assertIsString($rotated->json('secret'));
        $this->assertNotNull($rotated->json('secret_previous_expires_at'));

        $row = WebhookEndpoint::query()->where('public_id', $endpoint)->firstOrFail();
        $this->assertSame($rotated->json('secret'), $row->secret_current);
        $this->assertNotNull($row->secret_previous);

        $this->assertDatabaseHas('esign_audit_events', ['action' => 'webhook.endpoint.secret_rotated']);
    }

    public function test_a_zero_grace_rotation_cuts_the_old_secret_over_immediately(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);
        $endpoint = $this->endpoint($scenario, $issued);

        $this->postJson('/api/v1/webhooks/endpoints/'.$endpoint.'/rotate-secret',
            ['grace_hours' => 0], NativeApiScenario::headers($issued))->assertOk();

        $row = WebhookEndpoint::query()->where('public_id', $endpoint)->firstOrFail();

        $this->assertTrue($row->secret_previous_expires_at->lessThanOrEqualTo(now()));
    }

    public function test_an_unknown_endpoint_is_a_404(): void
    {
        $scenario = NativeApiScenario::create();

        $this->patchJson('/api/v1/webhooks/endpoints/01JC0000000000000000000001', ['description' => 'x'],
            NativeApiScenario::headers($scenario->credential([Scope::WebhooksManage])))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    private function endpoint(NativeApiScenario $scenario, $issued): string
    {
        return (string) $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
            'description' => 'Synthetic receiver',
            'event_filter' => ['signing_request.completed'],
        ], NativeApiScenario::headers($issued))->assertCreated()->json('id');
    }
}
