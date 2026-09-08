<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Delivery\Outbound\DestinationPolicy;
use App\Domain\Delivery\Outbound\HostResolver;
use App\Domain\Delivery\Webhooks\Models\WebhookEndpoint;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Integration\Native\Models\IdempotencyKey;
use App\Http\Middleware\AuthenticateServiceCredential;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeHostResolver;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * The `/api/v1` findings from docs/security/review-2026-09.md, each pinned by the behaviour
 * that was wrong rather than by the shape of the fix.
 */
class NativeApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same fixed answer table the webhook suite uses: a destination check that depended
        // on what this machine can resolve is a test that fails on somebody else's laptop.
        $this->app->instance(HostResolver::class, new FakeHostResolver([
            'receiver.example.test' => ['198.51.100.20'],
        ]));
        $this->app->forgetInstance(DestinationPolicy::class);
    }

    /**
     * Finding A-1. `bootstrap/app.php` never calls `throttleApi()`, so the framework's `api`
     * group carries no `throttle` middleware and no route file adds one. Every failed
     * attempt cost a query and a `Log::warning` line, forever, to anyone who asked.
     */
    public function test_repeated_authentication_failures_from_one_address_are_refused(): void
    {
        config()->set('esign.api.auth_failures_per_minute', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/me', ['Authorization' => 'Bearer esk_aaaaaaaaaaaa_'.str_repeat('b', 43)])
                ->assertUnauthorized();
        }

        $refused = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer esk_aaaaaaaaaaaa_'.str_repeat('b', 43)]);

        $refused->assertStatus(429);
        $refused->assertHeader('Retry-After');
        $refused->assertJsonPath('error.code', 'too_many_requests');
    }

    public function test_a_working_credential_never_spends_the_failure_budget(): void
    {
        config()->set('esign.api.auth_failures_per_minute', 3);

        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))->assertOk();
        }
    }

    public function test_the_ceiling_can_be_switched_off_where_an_edge_proxy_owns_it(): void
    {
        config()->set('esign.api.auth_failures_per_minute', 0);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/me', ['Authorization' => 'Bearer esk_aaaaaaaaaaaa_'.str_repeat('b', 43)])
                ->assertUnauthorized();
        }
    }

    /**
     * Finding A-5. Unknown prefix and wrong secret already returned an identical status, body,
     * and headers. They did not cost the same: only the known prefix eager-loaded a workspace,
     * so a known prefix was one database round trip slower, and only the known prefix reached
     * a digest and a `hash_equals`.
     *
     * Asserted on query count rather than on a stopwatch, which is the deterministic half of
     * the same statement — the extra `SELECT` was the dominant signal.
     */
    public function test_an_unknown_prefix_and_a_wrong_secret_cost_the_same(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();
        $knownPrefix = substr($issued->secret, 0, strpos($issued->secret, '_', 4) ?: 16);

        $unknown = $this->countQueriesFor('esk_zzzzzzzzzzzz_'.str_repeat('y', 43));
        $wrongSecret = $this->countQueriesFor($knownPrefix.'_'.str_repeat('y', 43));

        $this->assertSame($unknown, $wrongSecret, 'A known prefix must not cost an extra query.');
    }

    /**
     * Finding A-2. Two endpoints return a webhook signing secret, and `ApiIdempotency` records
     * every 2xx body verbatim — so `docs/api/native-v1.md`'s "only ciphertext is stored"
     * became false for anything created or rotated in the last 24 hours. The endpoint's own
     * `secret_current` was already an `encrypted` cast; this copy was not.
     */
    public function test_a_recorded_webhook_secret_is_not_readable_in_the_idempotency_table(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);

        $response = $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
            'description' => 'Synthetic receiver',
        ], NativeApiScenario::headers($issued, ['Idempotency-Key' => 'create-1']))->assertCreated();

        $secret = (string) $response->json('secret');
        $this->assertNotSame('', $secret);

        // The stored column, read past the cast, is ciphertext.
        $stored = (string) DB::table('api_idempotency_keys')->where('key', 'create-1')->value('response_body');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString('whsec_', $stored);

        // And the replay a dropped connection depends on still works, which is the whole
        // reason the body is recorded at all.
        $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
            'description' => 'Synthetic receiver',
        ], NativeApiScenario::headers($issued, ['Idempotency-Key' => 'create-1']))
            ->assertCreated()
            ->assertJsonPath('secret', $secret);

        $this->assertSame(1, WebhookEndpoint::query()->count());
    }

    /**
     * Finding A-3. Every Form Request here validates `$request->all()`, which merges the query
     * string — but the idempotency fingerprint covered only method, path, and body. So a
     * zero-grace cutover after a leak, sent with a key already used for a 24-hour rotation,
     * was answered with a replay: a 200 carrying a secret, and no rotation. That is the exact
     * silent no-op `idempotency_key_reused` exists to prevent.
     */
    public function test_the_same_key_with_a_different_query_string_is_refused_rather_than_replayed(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);

        $endpoint = (string) $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
        ], NativeApiScenario::headers($issued))->assertCreated()->json('id');

        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'rotate-1']);
        $path = '/api/v1/webhooks/endpoints/'.$endpoint.'/rotate-secret';

        $first = $this->postJson($path.'?grace_hours=24', [], $headers)->assertOk();

        $second = $this->postJson($path.'?grace_hours=0', [], $headers);

        $second->assertStatus(422);
        $second->assertJsonPath('error.code', 'idempotency_key_reused');

        // The overlap the first call opened is still open, so nothing was silently changed
        // either — the caller is told to use a different key, not handed a stale answer.
        $row = WebhookEndpoint::query()->where('public_id', $endpoint)->firstOrFail();
        $this->assertSame($first->json('secret'), $row->secret_current);
        $this->assertNotNull($row->secret_previous);
    }

    public function test_parameter_order_in_a_query_string_does_not_defeat_a_legitimate_replay(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::WebhooksManage]);

        $endpoint = (string) $this->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://receiver.example.test/hooks/esign',
        ], NativeApiScenario::headers($issued))->assertCreated()->json('id');

        $headers = NativeApiScenario::headers($issued, ['Idempotency-Key' => 'patch-1']);
        $path = '/api/v1/webhooks/endpoints/'.$endpoint;

        $first = $this->patchJson($path.'?description=Renamed&enabled=1', [], $headers)->assertOk();
        $second = $this->patchJson($path.'?enabled=1&description=Renamed', [], $headers)->assertOk();

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, IdempotencyKey::query()->count());
    }

    /**
     * Finding A-4. `Route::fallback()` registers for GET only, so an unmatched non-GET under
     * `/api/v1` was refused by the router before any route middleware ran — which meant
     * `ApiErrorBoundary` never saw it. The answer was Laravel's flat shape naming the
     * supported verbs, and with `APP_DEBUG` on, a stack trace with absolute paths on an
     * endpoint that needs no credential.
     */
    public function test_an_unmatched_non_get_under_the_api_answers_in_the_native_error_shape(): void
    {
        config()->set('app.debug', true);

        $response = $this->postJson('/api/v1/no-such-endpoint', ['x' => 1]);

        $response->assertStatus(405);
        $response->assertJsonPath('error.code', 'method_not_allowed');
        $response->assertJsonStructure(['error' => ['code', 'message']]);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString('.php', $body);
        // The verb list is a route-existence oracle and is not part of the contract.
        $this->assertStringNotContainsString('Supported methods', $body);
    }

    private function countQueriesFor(string $presented): int
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$presented])->assertUnauthorized();

        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        return $queries;
    }

    protected function tearDown(): void
    {
        app(RateLimiter::class)->clear(
            'esign:api:auth-failure:'.hash('sha256', '127.0.0.1'),
        );

        parent::tearDown();
    }

    /** Referenced so the constant is not silently renamed out from under the limiter key. */
    public function test_the_failure_window_is_a_minute(): void
    {
        $this->assertSame(60, AuthenticateServiceCredential::FAILURE_WINDOW_SECONDS);
    }
}
