<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use BWH\Auth\OAuth\ProviderApplications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Administrator single sign-on: what the callback is allowed to conclude from a provider
 * response, and what it must refuse to conclude.
 *
 * The negative cases carry most of the weight. A callback that accepts a replayed
 * authorization code, or that decides who somebody is from the email address the provider
 * happened to send, is a working login and a broken one at the same time — it passes every
 * happy-path test there is.
 */
class OAuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'sso'];

    private const ISSUER = 'example-provider';

    private const BASE_URL = 'https://identity.example.test';

    private const SUBJECT = 'a4e1c0de-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        // Restated in full: the package merges its own defaults shallowly, so an omitted key
        // here would be blank rather than inherited.
        config(['bherila-auth.oauth_client' => [
            'provider' => self::ISSUER,
            'base_url' => self::BASE_URL,
            'client_id' => 'esign-client',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'http://localhost/auth/callback',
            'scope' => 'identity:read',
            'authorize_path' => '/oauth/authorize',
            'token_path' => '/oauth/token',
            'identity_path' => '/api/oauth/user',
            'end_session_path' => '/oauth/end-session',
        ]]);
    }

    // ------------------------------------------------------------------ the redirect

    public function test_the_redirect_starts_an_authorization_code_flow_with_state_and_pkce(): void
    {
        $response = $this->get('/auth/redirect');

        $response->assertRedirectContains(self::BASE_URL.'/oauth/authorize?');
        $response->assertSessionHas('oauth.login.state');
        $response->assertSessionHas('oauth.login.code_verifier');

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('esign-client', $query['client_id']);
        $this->assertSame(session('oauth.login.state'), $query['state']);
        $this->assertNotSame(session('oauth.login.code_verifier'), $query['code_challenge']);
    }

    public function test_the_sign_in_page_offers_single_sign_on_and_no_password_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-mode="sso"', false)
            ->assertSee('data-sso-url="'.route('auth.redirect').'"', false)
            ->assertSee('data-login-url=""', false);
    }

    // ------------------------------------------------------------------ the happy paths

    public function test_an_unknown_subject_is_admitted_and_given_no_workspace_at_all(): void
    {
        $this->fakeProvider();

        $this->completeCallback()->assertRedirect(route('dashboard'));

        $binding = IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail();
        $user = $binding->user;

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Synthetic Operator', $user->name);
        $this->assertSame('synthetic@example.test', $user->email);
        $this->assertNotNull($binding->last_seen_at);

        // The whole of "first login is not an administrator": admitted, and given nothing.
        $this->assertSame(0, $user->workspaceMemberships()->count());
        $this->assertDatabaseCount('workspace_memberships', 0);

        // And the landing page says so rather than showing an empty table.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('You do not belong to a workspace yet');

        $this->assertDatabaseHas('esign_audit_events', [
            'action' => 'identity.user_provisioned',
            'subject_id' => (string) $user->getKey(),
        ]);
    }

    public function test_an_existing_binding_signs_in_and_the_provider_refreshes_the_placeholder_details(): void
    {
        // The shape `esign:bootstrap-owner` leaves behind: a placeholder row, a binding, an
        // owner membership, and no sign-in yet.
        $user = User::factory()->create([
            'name' => 'Pending owner (a4e1c0de…)',
            'email' => 'sso-placeholder@invalid',
        ]);
        IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => self::ISSUER,
            'subject' => self::SUBJECT,
            'last_seen_at' => null,
        ]);
        $workspace = Workspace::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->getKey(), 'role' => 'owner']);

        $this->fakeProvider();

        $this->completeCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertDatabaseCount('users', 1);

        $user->refresh();
        $this->assertSame('Synthetic Operator', $user->name);
        $this->assertSame('synthetic@example.test', $user->email);
        $this->assertNotNull($user->identityBindings()->sole()->last_seen_at);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Your workspaces')
            ->assertSee($workspace->name);
    }

    public function test_the_application_list_is_remembered_server_side(): void
    {
        $apps = [['key' => 'other', 'name' => 'Other Application', 'url' => 'https://other.example.test']];

        $this->fakeProvider(['apps' => $apps]);

        $this->completeCallback();

        $this->assertSame($apps, ProviderApplications::forRequest(request()));
        $this->assertSame($apps, session(ProviderApplications::SESSION_KEY));
    }

    public function test_a_successful_login_is_recorded_in_the_authentication_audit_trail(): void
    {
        $this->fakeProvider();
        $this->completeCallback();

        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_SUCCEEDED,
            'auth_method' => 'oauth',
            'succeeded' => true,
        ]);
    }

    // ------------------------------------------------------------------ email is not identity

    public function test_a_second_subject_reporting_the_same_address_becomes_a_second_account(): void
    {
        $first = User::factory()->create(['name' => 'First Person', 'email' => 'shared@example.test']);
        IdentityBinding::create([
            'user_id' => $first->getKey(),
            'issuer' => self::ISSUER,
            'subject' => 'subject-one',
            'last_seen_at' => now(),
        ]);

        // Same address, different subject. These are two people until the provider says
        // otherwise, and the provider has not said otherwise — it has only sent an address.
        $this->fakeProvider(['sub' => 'subject-two', 'name' => 'Second Person', 'email' => 'shared@example.test']);

        $this->completeCallback()->assertRedirect(route('dashboard'));

        $this->assertSame(2, User::where('email', 'shared@example.test')->count());

        $second = IdentityBinding::query()->forIssuerSubject(self::ISSUER, 'subject-two')->firstOrFail()->user;
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertAuthenticatedAs($second);

        // The first account is untouched: not renamed, not re-bound, not signed in as.
        $first->refresh();
        $this->assertSame('First Person', $first->name);
        $this->assertSame('subject-one', $first->identityBindings()->sole()->subject);
    }

    public function test_a_local_account_with_the_same_address_is_not_adopted(): void
    {
        $local = User::factory()->create(['name' => 'Local Operator', 'email' => 'synthetic@example.test']);

        $this->fakeProvider();

        $this->completeCallback();

        $bound = IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail()->user;

        $this->assertNotSame($local->getKey(), $bound->getKey());
        $this->assertSame(0, $local->identityBindings()->count());
        $this->assertAuthenticatedAs($bound);
    }

    // ------------------------------------------------------------------ refusals

    public function test_a_mismatched_state_is_refused_before_the_code_is_redeemed(): void
    {
        $this->fakeProvider();

        $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => 'expected-verifier',
        ])->get('/auth/callback?state=forged-state&code=authorization-code')
            ->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_a_callback_with_no_session_state_is_refused(): void
    {
        $this->fakeProvider();

        $this->get('/auth/callback?state=anything&code=authorization-code')->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_a_rejected_authorization_code_signs_nobody_in(): void
    {
        Http::fake([
            self::BASE_URL.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->completeCallback()->assertStatus(502);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('identity_bindings', 0);
    }

    public function test_an_unusable_identity_response_signs_nobody_in(): void
    {
        Http::fake([
            self::BASE_URL.'/oauth/token' => Http::response(['access_token' => 'access-token']),
            // No subject. Without one there is nothing to bind to, and an email address is
            // not a substitute.
            self::BASE_URL.'/api/oauth/user' => Http::response(['name' => 'Nobody', 'email' => 'nobody@example.test']),
        ]);

        $this->completeCallback()->assertStatus(502);

        $this->assertGuest();
        $this->assertDatabaseCount('identity_bindings', 0);
    }

    public function test_a_replayed_callback_is_refused(): void
    {
        $this->fakeProvider();

        $this->completeCallback()->assertRedirect(route('dashboard'));

        // The same authorization code and state, a second time. The state was consumed out
        // of the session by the first request, so there is nothing left to compare against.
        $this->get('/auth/callback?state=expected-state&code=authorization-code')->assertForbidden();

        $this->assertDatabaseCount('users', 1);
        Http::assertSentCount(2);
    }

    public function test_a_disabled_account_is_refused(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);
        IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => self::ISSUER,
            'subject' => self::SUBJECT,
            'last_seen_at' => now()->subDay(),
        ]);

        $this->fakeProvider();

        $this->completeCallback()->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'auth_method' => 'oauth',
            'reason' => 'Account disabled',
        ]);

        // Nothing about the refused sign-in is left in the visitor's session.
        $this->assertNull(session(ProviderApplications::SESSION_KEY));
    }

    // ------------------------------------------------------------------ signing out

    public function test_signing_out_ends_the_session_at_the_provider_too(): void
    {
        $user = User::factory()->create();

        // Ending only the local session leaves the provider still recognising this person,
        // so the next protected page hands them straight back with no prompt.
        $this->actingAs($user)
            ->post('/auth/logout')
            ->assertRedirect(
                self::BASE_URL.'/oauth/end-session?client_id=esign-client'
                    .'&post_logout_redirect_uri='.urlencode(url('/')),
            );

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGGED_OUT,
            'user_id' => $user->getKey(),
        ]);
    }

    // ------------------------------------------------------------------ the other mode is absent

    public function test_the_standalone_password_endpoints_do_not_exist(): void
    {
        // Not disabled, not guarded — absent. There is no local credential to submit.
        $this->post('/logout')->assertNotFound();
        $this->post('/login', ['email' => 'someone@example.test', 'password' => 'password'])
            ->assertMethodNotAllowed();
    }

    /**
     * Nothing in SSO mode can use a local password, so `config/bherila-auth.php` disables
     * the package's password-reset, change-password, two-factor, and passkey route families
     * along with it. Not disabled, not guarded — absent.
     */
    public function test_the_local_auth_route_families_do_not_exist(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'someone@example.test'])
            ->assertNotFound();
        $this->postJson('/api/auth/reset-password', [])->assertNotFound();
        $this->post('/api/change-password')->assertNotFound();
        $this->postJson('/api/auth/two-factor/verify', [])->assertNotFound();
        $this->postJson('/api/auth/two-factor/resend', [])->assertNotFound();
        $this->getJson('/api/passkeys')->assertNotFound();
        $this->postJson('/api/passkeys/register/options', [])->assertNotFound();
        $this->postJson('/api/passkeys/auth/options', [])->assertNotFound();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $identity  Overrides for the provider's identity response.
     */
    private function fakeProvider(array $identity = []): void
    {
        Http::fake([
            self::BASE_URL.'/oauth/token' => Http::response(['access_token' => 'access-token']),
            self::BASE_URL.'/api/oauth/user' => Http::response([
                'sub' => self::SUBJECT,
                'name' => 'Synthetic Operator',
                'email' => 'synthetic@example.test',
                ...$identity,
            ]),
        ]);
    }

    private function completeCallback(): TestResponse
    {
        return $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => 'expected-verifier',
        ])->get('/auth/callback?state=expected-state&code=authorization-code');
    }
}
