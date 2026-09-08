<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Credentials;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Models\Workspace;
use App\Http\Middleware\AuthenticateServiceCredential;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The HTTP contract of the `service-credential` and `require-scope` middleware.
 *
 * The routes exercised here are declared in `defineProbeRoutes()` and exist only for the
 * duration of a test. They are deliberately not in `routes/api.php`: the real API surfaces
 * do not exist yet (issues in the Integration module), and a permanent test endpoint that
 * echoes the authenticated principal is exactly the kind of thing that survives into
 * production. The probe route for a workspace-scoped lookup constrains its query by the
 * principal's workspace *before* using the identifier from the URL, which is the pattern
 * every real route must follow (docs/HANDOFF.md section 10).
 */
class ServiceCredentialAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $alpha;

    private Workspace $beta;

    private ServiceCredentialIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Workspace::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->beta = Workspace::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->issuer = app(ServiceCredentialIssuer::class);

        $this->defineProbeRoutes();
    }

    public function test_a_raw_api_key_in_the_authorization_header_authenticates(): void
    {
        $issued = $this->issueFor($this->alpha);

        // The consumer's Firma-style syntax: the key itself, no scheme.
        $response = $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret]);

        $response->assertOk()->assertJson([
            'credential_prefix' => $issued->credential->prefix,
            'attribute_workspace_id' => $this->alpha->getKey(),
            'principal_workspace_id' => $this->alpha->getKey(),
            'principal_workspace_slug' => 'alpha',
            'scopes' => ['envelopes:read'],
        ]);
    }

    public function test_a_bearer_token_authenticates_in_either_casing(): void
    {
        $issued = $this->issueFor($this->alpha);

        $this->getJson('/testing/credentials/probe', ['Authorization' => 'Bearer '.$issued->secret])
            ->assertOk()
            ->assertJsonPath('credential_prefix', $issued->credential->prefix);

        // HTTP auth schemes are case-insensitive.
        $this->getJson('/testing/credentials/probe', ['Authorization' => 'bearer  '.$issued->secret])
            ->assertOk()
            ->assertJsonPath('credential_prefix', $issued->credential->prefix);
    }

    public function test_no_header_is_rejected(): void
    {
        $this->getJson('/testing/credentials/probe')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_credential')
            ->assertHeader('WWW-Authenticate', 'Bearer realm="esign", error="invalid_token"');
    }

    public function test_another_authentication_scheme_is_not_mistaken_for_a_key(): void
    {
        $this->getJson('/testing/credentials/probe', ['Authorization' => 'Basic '.base64_encode('user:pass')])
            ->assertUnauthorized();
    }

    public function test_an_unknown_prefix_is_rejected(): void
    {
        $this->issueFor($this->alpha);

        $this->getJson('/testing/credentials/probe', [
            'Authorization' => 'esk_zzzzzzzzzzzz_'.Str::lower(Str::random(43)),
        ])->assertUnauthorized()->assertJsonPath('message', 'Invalid API credential.');
    }

    public function test_a_wrong_secret_with_a_valid_prefix_is_rejected_and_does_not_count_as_use(): void
    {
        $issued = $this->issueFor($this->alpha);

        $response = $this->getJson('/testing/credentials/probe', [
            'Authorization' => $this->wrongSecretFor($issued),
        ]);

        $response->assertUnauthorized();
        // Identical to the unknown-prefix answer: a caller that has not proved it holds a
        // secret learns nothing about which prefixes exist.
        $response->assertJsonPath('message', 'Invalid API credential.');
        $this->assertNull($issued->credential->refresh()->last_used_at);
    }

    public function test_a_revoked_credential_is_rejected_immediately(): void
    {
        $issued = $this->issueFor($this->alpha);
        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])->assertOk();

        $this->issuer->revoke($issued->credential, AuditActor::console('test'));

        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This API credential has been revoked.');
    }

    public function test_an_expired_credential_is_rejected(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $issued = $this->issueFor($this->alpha, expiresAt: CarbonImmutable::parse('2026-09-08 11:00:00'));

        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])->assertOk();

        $this->travel(61)->minutes();

        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This API credential expired at 2026-09-08T11:00:00+00:00.');
    }

    public function test_a_credential_in_a_deleted_workspace_is_rejected(): void
    {
        $issued = $this->issueFor($this->alpha);

        $this->alpha->delete();

        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid API credential.');
    }

    public function test_rotation_leaves_both_secrets_working_until_the_overlap_ends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $original = $this->issueFor($this->alpha);
        $rotated = $this->issuer->rotate($original->credential, AuditActor::console('test'), CarbonInterval::hours(24));

        // During the window a redeploying consumer may present either.
        $this->getJson('/testing/credentials/probe', ['Authorization' => $original->secret])->assertOk();
        $this->getJson('/testing/credentials/probe', ['Authorization' => $rotated->secret])->assertOk();

        $this->travel(25)->hours();

        $this->getJson('/testing/credentials/probe', ['Authorization' => $original->secret])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This API credential expired at 2026-09-09T10:00:00+00:00.');
        $this->getJson('/testing/credentials/probe', ['Authorization' => $rotated->secret])->assertOk();
    }

    public function test_a_route_scope_is_enforced(): void
    {
        $readOnly = $this->issueFor($this->alpha, [Scope::EnvelopesRead->value]);

        $this->getJson('/testing/credentials/envelopes', ['Authorization' => $readOnly->secret])->assertOk();

        $this->getJson('/testing/credentials/webhooks', ['Authorization' => $readOnly->secret])
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope')
            ->assertJsonPath('required_scope', 'webhooks:manage')
            ->assertJsonPath('message', "This API credential is not granted 'webhooks:manage'.");
    }

    public function test_a_route_naming_several_scopes_requires_all_of_them(): void
    {
        $readOnly = $this->issueFor($this->alpha, [Scope::EnvelopesRead->value]);
        $readWrite = $this->issueFor($this->alpha, [Scope::EnvelopesRead->value, Scope::EnvelopesWrite->value]);

        $this->getJson('/testing/credentials/envelopes/patch', ['Authorization' => $readOnly->secret])
            ->assertForbidden()
            ->assertJsonPath('required_scope', 'envelopes:write');

        $this->getJson('/testing/credentials/envelopes/patch', ['Authorization' => $readWrite->secret])->assertOk();
    }

    public function test_a_scoped_route_without_a_credential_is_unauthorized_not_forbidden(): void
    {
        $this->getJson('/testing/credentials/envelopes')->assertUnauthorized();
    }

    public function test_a_credential_cannot_reach_another_workspaces_resource(): void
    {
        $issued = $this->issueFor($this->alpha);

        $this->getJson('/testing/credentials/workspaces/'.$this->alpha->public_id, ['Authorization' => $issued->secret])
            ->assertOk()
            ->assertJsonPath('slug', 'alpha');

        // Beta exists and its public id is correct; the credential is still told nothing.
        // The lookup is constrained by workspace before the identifier is used, so the
        // answer is indistinguishable from "no such workspace".
        $this->getJson('/testing/credentials/workspaces/'.$this->beta->public_id, ['Authorization' => $issued->secret])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');
    }

    public function test_last_used_at_is_recorded_and_then_throttled_to_once_a_minute(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $issued = $this->issueFor($this->alpha);
        $credential = $issued->credential;
        $updatedAt = $credential->refresh()->updated_at?->toDateTimeString();

        $this->assertNull($credential->last_used_at);

        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])->assertOk();
        $this->assertSame('2026-09-08 10:00:00', $credential->refresh()->last_used_at?->toDateTimeString());

        // Inside the throttle window: no write.
        $this->travel(30)->seconds();
        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])->assertOk();
        $this->assertSame('2026-09-08 10:00:00', $credential->refresh()->last_used_at?->toDateTimeString());

        // Past it: one write.
        $this->travel(31)->seconds();
        $this->getJson('/testing/credentials/probe', ['Authorization' => $issued->secret])->assertOk();
        $this->assertSame('2026-09-08 10:01:01', $credential->refresh()->last_used_at?->toDateTimeString());

        $this->assertSame(
            $updatedAt,
            $credential->refresh()->updated_at?->toDateTimeString(),
            'Recording use must not move updated_at, which means "the credential itself changed".',
        );
    }

    public function test_a_failed_authentication_never_logs_the_secret(): void
    {
        $issued = $this->issueFor($this->alpha);
        $wrong = $this->wrongSecretFor($issued);

        Log::spy();

        $this->getJson('/testing/credentials/probe', ['Authorization' => $wrong])->assertUnauthorized();

        // The warning must exist (an operator needs to see brute-force attempts) and must
        // mention the prefix and the reason and nothing else. If the secret leaked into the
        // message or the context, no call matches and this assertion fails.
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context) use ($wrong, $issued): bool {
                return ! str_contains($message.'|'.(string) json_encode($context), $wrong)
                    && ! str_contains($message.'|'.(string) json_encode($context), $this->secretHalf($wrong))
                    && ($context['reason'] ?? null) === 'secret_mismatch'
                    && ($context['credential_prefix'] ?? null) === $issued->credential->prefix;
            },
        );
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issueFor(
        Workspace $workspace,
        array $scopes = [Scope::EnvelopesRead->value],
        ?CarbonImmutable $expiresAt = null,
    ): IssuedServiceCredential {
        return $this->issuer->issue(
            $workspace,
            'probe',
            $scopes,
            AuditActor::console('test'),
            $expiresAt,
        );
    }

    /**
     * A correctly shaped secret carrying a real prefix and the wrong random half.
     */
    private function wrongSecretFor(IssuedServiceCredential $issued): string
    {
        return $issued->credential->prefix.'_'.Str::lower(Str::random(43));
    }

    private function secretHalf(string $secret): string
    {
        return substr($secret, (int) strrpos($secret, '_') + 1);
    }

    private function defineProbeRoutes(): void
    {
        Route::middleware('service-credential')->get('/testing/credentials/probe', function (Request $request) {
            $credential = $request->attributes->get(AuthenticateServiceCredential::CREDENTIAL_ATTRIBUTE);
            $principal = app(CurrentPrincipal::class);

            return response()->json([
                'credential_prefix' => $credential?->prefix,
                'attribute_workspace_id' => $request->attributes->get(AuthenticateServiceCredential::WORKSPACE_ID_ATTRIBUTE),
                'attribute_workspace_slug' => $request->attributes->get(AuthenticateServiceCredential::WORKSPACE_ATTRIBUTE)?->slug,
                'principal_workspace_id' => $principal->workspaceIdOrFail(),
                'principal_workspace_slug' => $principal->workspaceOrFail()->slug,
                'scopes' => array_map(static fn (Scope $scope): string => $scope->value, $principal->grantedScopes()),
            ]);
        });

        Route::middleware(['service-credential', 'require-scope:envelopes:read'])
            ->get('/testing/credentials/envelopes', fn () => response()->json(['ok' => true]));

        Route::middleware(['service-credential', 'require-scope:envelopes:read,envelopes:write'])
            ->get('/testing/credentials/envelopes/patch', fn () => response()->json(['ok' => true]));

        Route::middleware(['service-credential', 'require-scope:webhooks:manage'])
            ->get('/testing/credentials/webhooks', fn () => response()->json(['ok' => true]));

        // Workspace scope is applied to the query, not checked after the fact.
        Route::middleware(['service-credential', 'require-scope:envelopes:read'])
            ->get('/testing/credentials/workspaces/{publicId}', function (string $publicId) {
                $workspace = Workspace::query()
                    ->whereKey(app(CurrentPrincipal::class)->workspaceIdOrFail())
                    ->where('public_id', $publicId)
                    ->first();

                return $workspace === null
                    ? response()->json(['message' => 'Not found.'], 404)
                    : response()->json(['slug' => $workspace->slug]);
            });
    }
}
