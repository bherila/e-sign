<?php

declare(strict_types=1);

namespace Tests\Feature\Integration\Native;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * Who may call the native API, and what a refusal looks like.
 *
 * The credential middleware itself is covered by
 * tests/Feature/Identity/Credentials/ServiceCredentialAuthenticationTest.php over its own
 * probe routes. What is asserted here is the half that belongs to this surface: that the
 * real `/api/v1` routes are actually behind it, that each route requires the scope it claims
 * to, and that every refusal arrives in this API's error envelope rather than the flat shape
 * the shared middleware raises.
 */
class NativeApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_with_no_credential_is_refused_in_the_native_error_shape(): void
    {
        NativeApiScenario::create();

        $response = $this->getJson('/api/v1/me');

        $response->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credential')
            ->assertJsonPath('error.message', 'No API credential was presented.');

        // The challenge the shared middleware raises survives the rewrite.
        $this->assertStringContainsString('Bearer', (string) $response->headers->get('WWW-Authenticate'));

        // The flat shape the facade needs is gone from this surface.
        $response->assertJsonMissingPath('message');
    }

    public function test_an_unknown_secret_is_refused(): void
    {
        NativeApiScenario::create();

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer esk_notarealprefix_notarealsecret'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credential');
    }

    public function test_the_raw_key_syntax_authenticates_as_well_as_bearer(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $this->getJson('/api/v1/me', ['Authorization' => $issued->secret])
            ->assertOk()
            ->assertJsonPath('credential.prefix', $issued->credential->prefix)
            ->assertJsonPath('workspace.id', $scenario->workspace->public_id);

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$issued->secret])
            ->assertOk()
            ->assertJsonPath('credential.prefix', $issued->credential->prefix);
    }

    public function test_a_revoked_credential_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        app(ServiceCredentialIssuer::class)->revoke(
            $issued->credential,
            AuditActor::system('tests'),
            'secret pasted into a ticket',
        );

        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credential')
            ->assertJsonPath('error.message', 'This API credential has been revoked.');
    }

    public function test_an_expired_credential_is_refused(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential(expiresAt: CarbonImmutable::now()->addMinute());

        $this->travel(2)->minutes();

        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_credential');
    }

    public function test_a_lapsed_rotation_overlap_stops_authenticating(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $successor = app(ServiceCredentialIssuer::class)->rotate(
            $issued->credential,
            AuditActor::system('tests'),
            CarbonInterval::hours(1),
        );

        // Inside the overlap both secrets work; that is the whole point of a rotation window.
        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))->assertOk();
        $this->getJson('/api/v1/me', NativeApiScenario::headers($successor))->assertOk();

        $this->travel(2)->hours();

        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))->assertUnauthorized();
        $this->getJson('/api/v1/me', NativeApiScenario::headers($successor))->assertOk();
    }

    public function test_me_reports_the_credential_its_workspace_and_its_scopes(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::EnvelopesRead, Scope::WebhooksManage], label: 'polling integration');

        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))
            ->assertOk()
            ->assertJsonPath('credential.label', 'polling integration')
            ->assertJsonPath('workspace.slug', $scenario->workspace->slug)
            ->assertExactJson([
                'credential' => [
                    'prefix' => $issued->credential->prefix,
                    'label' => 'polling integration',
                    'expires_at' => null,
                    'last_used_at' => $issued->credential->refresh()->last_used_at?->toIso8601String(),
                    'created_at' => $issued->credential->created_at?->toIso8601String(),
                ],
                'workspace' => [
                    'id' => $scenario->workspace->public_id,
                    'name' => $scenario->workspace->name,
                    'slug' => $scenario->workspace->slug,
                ],
                'scopes' => ['envelopes:read', 'webhooks:manage'],
            ]);
    }

    public function test_me_never_carries_secret_material(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $body = (string) $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))->getContent();

        $this->assertStringNotContainsString($issued->secret, $body);
        $this->assertStringNotContainsString($issued->credential->secret_hash, $body);
        $this->assertStringNotContainsString($issued->credential->secret_salt, $body);
    }

    /**
     * Every route names a scope, and holding a different one is not enough.
     *
     * The data set is the whole surface rather than a sample: a route added later without a
     * scope is the kind of thing nobody notices until it is a finding. The ids are
     * well-formed ULIDs that resolve to nothing, because the scope check has to fail
     * *before* the lookup — if it did not, a `404` here would hide a missing scope.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function scopedRoutes(): iterable
    {
        $envelope = '01JC0000000000000000000001';
        $template = '01JC0000000000000000000002';
        $endpoint = '01JC0000000000000000000003';

        yield 'list templates' => ['GET', '/api/v1/templates', 'templates:read'];
        yield 'read a template' => ['GET', '/api/v1/templates/'.$template, 'templates:read'];
        yield 'create an envelope' => ['POST', '/api/v1/envelopes', 'envelopes:write'];
        yield 'read an envelope' => ['GET', '/api/v1/envelopes/'.$envelope, 'envelopes:read'];
        yield 'patch an envelope' => ['PATCH', '/api/v1/envelopes/'.$envelope, 'envelopes:write'];
        yield 'send an envelope' => ['POST', '/api/v1/envelopes/'.$envelope.'/send', 'envelopes:write'];
        yield 'cancel an envelope' => ['POST', '/api/v1/envelopes/'.$envelope.'/cancel', 'envelopes:write'];
        yield 'read recipients' => ['GET', '/api/v1/envelopes/'.$envelope.'/recipients', 'envelopes:read'];
        yield 'read values' => ['GET', '/api/v1/envelopes/'.$envelope.'/values', 'envelopes:read'];
        yield 'read events' => ['GET', '/api/v1/envelopes/'.$envelope.'/events', 'envelopes:read'];
        yield 'list artifacts' => ['GET', '/api/v1/envelopes/'.$envelope.'/artifacts', 'envelopes:read'];
        yield 'download an artifact' => ['GET', '/api/v1/envelopes/'.$envelope.'/artifacts/a1/download', 'envelopes:read'];
        yield 'list endpoints' => ['GET', '/api/v1/webhooks/endpoints', 'webhooks:manage'];
        yield 'create an endpoint' => ['POST', '/api/v1/webhooks/endpoints', 'webhooks:manage'];
        yield 'patch an endpoint' => ['PATCH', '/api/v1/webhooks/endpoints/'.$endpoint, 'webhooks:manage'];
        yield 'retire an endpoint' => ['DELETE', '/api/v1/webhooks/endpoints/'.$endpoint, 'webhooks:manage'];
        yield 'rotate a secret' => ['POST', '/api/v1/webhooks/endpoints/'.$endpoint.'/rotate-secret', 'webhooks:manage'];
    }

    #[DataProvider('scopedRoutes')]
    public function test_each_route_requires_its_own_scope(string $method, string $uri, string $required): void
    {
        $scenario = NativeApiScenario::create();

        // A credential holding every scope except the one this route names.
        $others = array_values(array_filter(
            Scope::cases(),
            static fn (Scope $scope): bool => $scope->value !== $required,
        ));

        $issued = $scenario->credential($others);

        $this->json($method, $uri, [], NativeApiScenario::headers($issued))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope')
            ->assertJsonPath('error.details.required_scope', $required);
    }

    public function test_an_unknown_endpoint_answers_in_the_native_error_shape(): void
    {
        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential();

        $this->getJson('/api/v1/no-such-thing', NativeApiScenario::headers($issued))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_authentication_failures_never_log_the_presented_secret(): void
    {
        NativeApiScenario::create();

        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $secret = 'esk_probeprefix1_probesecretvalue';

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secret])->assertUnauthorized();

        $this->assertNotSame([], $logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('probesecretvalue', $line);
        }
    }
}
