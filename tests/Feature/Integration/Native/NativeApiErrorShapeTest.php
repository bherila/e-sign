<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Identity\Credentials\Scope;
use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\ErrorCode;
use App\Domain\Integration\Native\LocatedArtifact;
use App\Domain\Signing\Models\Envelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * One error shape, and nothing in it that should not be on a public endpoint.
 *
 * A client parses `error.code` and branches on it. If one endpoint answered `{message}` and
 * another `{error: {...}}`, every integration would grow a shape-sniffing helper, and the
 * first one written would be written against whichever endpoint the author hit first. So the
 * envelope is asserted across statuses rather than at one of them.
 *
 * The second half is the one that matters in production: a body must never carry a stack
 * trace, a file path, or a class name from a failure nobody anticipated.
 */
class NativeApiErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'unauthenticated' => [401, 'invalid_credential'];
        yield 'not found' => [404, 'not_found'];
        yield 'illegal transition' => [409, 'illegal_transition'];
        yield 'validation' => [422, 'validation_failed'];
    }

    #[DataProvider('refusals')]
    public function test_every_refusal_uses_the_same_envelope(int $status, string $code): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $response = match ($status) {
            401 => $this->getJson('/api/v1/me'),
            404 => $this->getJson('/api/v1/envelopes/01JC0000000000000000000001',
                NativeApiScenario::headers($issued)),
            409 => $this->sendTwice($scenario, $issued),
            default => $this->postJson('/api/v1/envelopes', [], NativeApiScenario::headers($issued)),
        };

        $response->assertStatus($status)->assertJsonPath('error.code', $code);

        $body = $response->json();

        $this->assertSame(['error'], array_keys($body));
        $this->assertContains($body['error']['code'], ErrorCode::values());
        $this->assertIsString($body['error']['message']);
        $this->assertNotSame('', $body['error']['message']);
        $this->assertSame([], array_diff(array_keys($body['error']), ['code', 'message', 'details']));
    }

    public function test_an_unexpected_failure_in_production_leaks_nothing(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $envelope = $this->completed($scenario);

        config(['app.debug' => false]);
        $this->app['env'] = 'production';

        $this->app->instance(ArtifactLocator::class, new class implements ArtifactLocator
        {
            public function forEnvelope(Envelope $envelope): array
            {
                throw new RuntimeException(
                    'Disk [artifacts] failed at /var/www/esign/storage/app/artifacts/sealed.pdf',
                );
            }

            public function find(Envelope $envelope, string $artifactId): ?LocatedArtifact
            {
                return null;
            }
        });

        $response = $this->getJson('/api/v1/envelopes/'.$envelope->public_id.'/artifacts',
            NativeApiScenario::headers($issued));

        $response->assertStatus(500)->assertJsonPath('error.code', 'internal_error');

        $body = (string) $response->getContent();

        foreach ([
            '/var/www',
            'storage/app',
            'RuntimeException',
            'vendor/laravel',
            '.php',
            'Stack trace',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }

        $this->assertSame(['error'], array_keys((array) $response->json()));
        $this->assertArrayNotHasKey('details', (array) $response->json('error'));
        $this->assertArrayNotHasKey('trace', (array) $response->json('error'));
    }

    public function test_a_403_names_the_scope_the_caller_is_missing(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::EnvelopesRead]);

        $this->postJson('/api/v1/envelopes', [], NativeApiScenario::headers($issued))
            ->assertForbidden()
            ->assertExactJson(['error' => [
                'code' => 'insufficient_scope',
                'message' => "This API credential is not granted 'envelopes:write'.",
                'details' => ['required_scope' => 'envelopes:write'],
            ]]);
    }

    private function sendTwice(NativeApiScenario $scenario, $issued)
    {
        $version = $scenario->publishedTemplateVersion();

        $envelope = (string) $this->postJson('/api/v1/envelopes', [
            'template_version_id' => $version->public_id,
            'values' => ['agreement_effective_date' => '2026-02-01'],
        ], NativeApiScenario::headers($issued))->assertCreated()->json('id');

        $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], NativeApiScenario::headers($issued))
            ->assertOk();

        return $this->postJson('/api/v1/envelopes/'.$envelope.'/send', [], NativeApiScenario::headers($issued));
    }

    private function completed(NativeApiScenario $scenario): Envelope
    {
        $signing = $scenario->signing;
        $envelope = $signing->sent();

        foreach (['buyer', 'seller'] as $index => $recipientId) {
            $signing->signAs($envelope->refresh(), $signing->recipient($envelope, $recipientId), 'session-'.$index);
        }

        $signing->machine()->markCompleted($envelope->refresh(), 'artifacts/synthetic-sealed.pdf');

        return $envelope->refresh();
    }
}
