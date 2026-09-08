<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthReadyEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the scheduler-heartbeat probe passing by default so these tests exercise
        // access control and status mapping rather than an unrelated probe.
        $this->app->make(SchedulerHeartbeat::class)->record();
    }

    public function test_a_request_from_an_allowlisted_source_gets_the_detailed_body(): void
    {
        // The Laravel test client defaults to REMOTE_ADDR 127.0.0.1, which is inside the
        // default ESIGN_HEALTH_ALLOW_CIDRS loopback allowlist.
        $response = $this->getJson('/health/ready');

        $response->assertOk();
        $response->assertJsonStructure(['status', 'probes']);
    }

    public function test_a_request_from_outside_the_allowlist_without_a_token_gets_status_only(): void
    {
        $response = $this->call('GET', '/health/ready', server: ['REMOTE_ADDR' => '203.0.113.5']);

        $response->assertOk();
        $body = $response->json();

        $this->assertSame(['status'], array_keys($body));
        $this->assertArrayNotHasKey('probes', $body);
    }

    public function test_an_invalid_token_from_outside_the_allowlist_gets_status_only(): void
    {
        config()->set('esign.health_token', 'the-real-token');

        $response = $this->call('GET', '/health/ready', server: [
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ]);

        $response->assertOk();
        $this->assertSame(['status'], array_keys($response->json()));
    }

    public function test_a_valid_token_from_outside_the_allowlist_gets_the_detailed_body(): void
    {
        config()->set('esign.health_token', 'the-real-token');

        $response = $this->call('GET', '/health/ready', server: [
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_AUTHORIZATION' => 'Bearer the-real-token',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'probes']);
    }

    public function test_overall_status_maps_to_a_503_when_a_probe_fails(): void
    {
        config()->set('esign.seal.certificate_path', '/does/not/exist/cert.pem');
        config()->set('esign.seal.private_key_path', '/does/not/exist/key.pem');
        $this->app['env'] = 'production';

        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJsonFragment(['status' => 'fail']);
    }

    public function test_the_detailed_body_never_leaks_configuration_secrets_or_paths(): void
    {
        config()->set('database.connections.sqlite.password', 'super-secret-db-password');
        config()->set('esign.seal.certificate_path', '/var/secrets/esign/cert.pem');
        config()->set('esign.seal.private_key_path', '/var/secrets/esign/key.pem');
        config()->set('mail.mailers.smtp.host', 'smtp.internal.example.test');

        $response = $this->getJson('/health/ready');
        $raw = $response->getContent();

        $this->assertIsString($raw);
        $this->assertStringNotContainsString(config('app.key'), $raw);
        $this->assertStringNotContainsString('super-secret-db-password', $raw);
        $this->assertStringNotContainsString('/var/secrets/esign/cert.pem', $raw);
        $this->assertStringNotContainsString('/var/secrets/esign/key.pem', $raw);
        $this->assertStringNotContainsString('smtp.internal.example.test', $raw);
    }
}
