<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Auth\IdentityBindingResolver;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use BWH\Auth\Http\Middleware\RequireActiveProviderSession;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakesProviderIdentity;
use Tests\Support\GuestSigningScenario;
use Tests\Support\NativeApiScenario;
use Tests\TestCase;

/**
 * Provider identity (lifecycle) enforcement on the administrative browser surface
 * (docs/operations/provider-identity.md): the SSO callback records the login generation, and
 * RequireActiveProviderSession ends a session the provider has since ended.
 *
 * Everything here is synthetic: one fake provider on a reserved host, subjects that are opaque
 * strings, addresses under example.test.
 */
class ProviderIdentityEnforcementTest extends TestCase
{
    use FakesProviderIdentity;
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'sso'];

    private const SUBJECT = 'b7c2d0de-0000-4000-8000-000000000001';

    private const SESSION_KEY = 'bherila_auth.provider_session';

    // ------------------------------------------------------------------ inert while off

    public function test_with_enforcement_off_sign_in_still_records_a_baseline(): void
    {
        $this->fakeProviderIdentity(enforced: false);
        $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT, 4);

        $this->signInThroughProvider(self::SUBJECT, 4)->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $baseline = session(self::SESSION_KEY);
        $this->assertIsArray($baseline);
        $this->assertSame(self::PROVIDER, $baseline['provider']);
        $this->assertSame(self::SUBJECT, $baseline['subject']);
        $this->assertSame(4, $baseline['generation']);
        $this->assertSame(0, $this->providerStatusRequests, 'Sign-in uses the login response, not a status lookup.');
    }

    public function test_with_enforcement_off_an_ended_identity_keeps_its_session_as_before(): void
    {
        $this->fakeProviderIdentity(enforced: false);
        $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        $this->disableProviderIdentity(self::SUBJECT);
        $this->takeProviderOffline();
        $this->travel(6)->minutes();

        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticated();
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_with_enforcement_off_a_provider_without_generations_still_signs_people_in(): void
    {
        $this->fakeProviderIdentity(enforced: false);
        $this->boundUser(self::SUBJECT);

        $this->signInThroughProvider(self::SUBJECT, null)->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNull(session(self::SESSION_KEY));
    }

    // ------------------------------------------------------------------ sign-in under enforcement

    public function test_with_enforcement_on_a_sign_in_that_cannot_be_verified_is_undone(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);

        // No credential generation in the login response: nothing to hold the session to.
        $this->signInThroughProvider(self::SUBJECT, null)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'auth_method' => 'oauth',
            'user_id' => $user->getKey(),
            'reason' => 'Provider session unverified',
        ]);
        $this->assertDatabaseMissing('auth_audit_log', ['event' => AuthAuditLog::EVENT_LOGIN_SUCCEEDED]);
    }

    public function test_with_enforcement_on_an_active_identity_reaches_the_administrative_pages(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $workspace = $this->workspaceFor($user);
        $this->providerIdentity(self::SUBJECT);

        $this->signInThroughProvider(self::SUBJECT, 1)->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();

        $this->travel(6)->minutes();
        $this->getJson('/workspaces/'.$workspace->public_id.'/templates')->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(1, $this->providerStatusRequests);
    }

    // ------------------------------------------------------------------ an identity the provider ends

    public function test_an_identity_the_provider_disables_is_signed_out_of_a_document_route(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $workspace = $this->workspaceFor($user);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        $this->disableProviderIdentity(self::SUBJECT);
        $this->travel(6)->minutes();

        $this->get('/workspaces/'.$workspace->public_id.'/documents/'.Str::ulid())
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session(self::SESSION_KEY));
    }

    public function test_an_ended_identity_on_a_json_route_is_answered_401(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $workspace = $this->workspaceFor($user);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        $this->disableProviderIdentity(self::SUBJECT);
        $this->travel(6)->minutes();

        $this->getJson('/workspaces/'.$workspace->public_id.'/documents/'.Str::ulid())
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your sign-in has ended. Sign in again.');

        $this->assertGuest();
    }

    public function test_a_write_checks_freshly_so_a_reset_at_the_provider_ends_the_session_at_once(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $workspace = $this->workspaceFor($user);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        // A password reset at the provider moves the person to a new credential generation.
        // No time passes: an unsafe method never relies on the freshness window.
        $this->providerIdentity(self::SUBJECT, 2);

        $this->postJson('/workspaces/'.$workspace->public_id.'/documents', [])
            ->assertUnauthorized();

        $this->assertGuest();
        $this->assertSame(1, $this->providerStatusRequests);
    }

    public function test_a_session_started_before_the_baseline_was_recorded_signs_in_again_once(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);

        // Signed in by a release that did not record a baseline.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ provider unavailable

    public function test_an_unavailable_provider_answers_503_and_keeps_the_session(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        $this->takeProviderOffline();
        $this->travel(6)->minutes();

        $this->get('/dashboard')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');
        $this->assertAuthenticatedAs($user->fresh());

        // Recovery: the same session continues once the provider answers again.
        $this->takeProviderOffline(false);
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_signing_out_stays_reachable_while_the_provider_is_unavailable(): void
    {
        $this->fakeProviderIdentity();
        $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        $this->takeProviderOffline();
        $this->travel(6)->minutes();

        $this->post('/auth/logout')->assertRedirectContains(self::PROVIDER_URL.'/oauth/end-session?');
        $this->assertGuest();
    }

    // ------------------------------------------------------------------ surfaces that are not provider identities

    public function test_a_guest_signing_link_is_not_a_provider_identity(): void
    {
        $this->fakeProviderIdentity();
        $this->takeProviderOffline();

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $this->get(GuestSigningScenario::landingUrl($issued))
            ->assertOk()
            ->assertSee($scenario->envelope->title);
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_a_guest_signing_link_opened_in_an_ended_administrators_browser_is_not_checked(): void
    {
        $this->fakeProviderIdentity();
        $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);
        $this->disableProviderIdentity(self::SUBJECT);
        $this->travel(6)->minutes();

        $scenario = GuestSigningScenario::sent();
        $issued = $scenario->invite();

        $this->get(GuestSigningScenario::landingUrl($issued))->assertOk();
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_a_service_credential_is_not_a_provider_identity(): void
    {
        $this->fakeProviderIdentity();
        $this->takeProviderOffline();

        $scenario = NativeApiScenario::create();
        $issued = $scenario->credential([Scope::TemplatesRead]);

        $this->getJson('/api/v1/me', NativeApiScenario::headers($issued))->assertOk();
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_an_unbound_account_is_left_to_the_login_policy(): void
    {
        $this->fakeProviderIdentity();
        $this->takeProviderOffline();

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $this->assertSame(0, $this->providerStatusRequests);
    }

    // ------------------------------------------------------------------ the binding resolver

    public function test_the_package_resolves_bindings_from_identity_bindings(): void
    {
        $this->fakeProviderIdentity();

        $this->assertInstanceOf(IdentityBindingResolver::class, app(ProviderBindingResolver::class));

        $bound = $this->boundUser(self::SUBJECT);
        $binding = app(ProviderBindingResolver::class)->binding($bound);
        $this->assertNotNull($binding);
        $this->assertSame(self::PROVIDER, $binding->provider);
        $this->assertSame(self::SUBJECT, $binding->subject);

        $this->assertNull(app(ProviderBindingResolver::class)->binding(User::factory()->create()));
    }

    public function test_a_binding_under_another_issuer_is_broken_rather_than_unbound(): void
    {
        $this->fakeProviderIdentity();
        $user = User::factory()->create();
        IdentityBinding::create([
            'user_id' => $user->getKey(), 'issuer' => 'retired-provider',
            'subject' => self::SUBJECT, 'last_seen_at' => now(),
        ]);

        $this->expectException(ProviderSessionExpired::class);
        app(ProviderBindingResolver::class)->binding($user);
    }

    public function test_an_account_with_two_bindings_is_broken_rather_than_guessed(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        IdentityBinding::create([
            'user_id' => $user->getKey(), 'issuer' => self::PROVIDER,
            'subject' => 'b7c2d0de-0000-4000-8000-000000000002', 'last_seen_at' => now(),
        ]);

        $this->expectException(ProviderSessionExpired::class);
        app(ProviderBindingResolver::class)->binding($user);
    }

    public function test_a_broken_binding_is_signed_out_under_enforcement(): void
    {
        $this->fakeProviderIdentity();
        $user = $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1);

        IdentityBinding::query()->where('user_id', $user->getKey())->update(['issuer' => 'retired-provider']);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_middleware_is_the_packages(): void
    {
        $this->assertSame(RequireActiveProviderSession::class, app('router')->getMiddleware()['provider-session'] ?? null);
    }

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Owner->value,
        ]);

        return $workspace;
    }
}
