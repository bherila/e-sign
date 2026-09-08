<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Models\IdentityBinding;
use App\Models\User;
use BWH\Auth\Models\AuthAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Standalone local-admin mode: an installation with no identity provider at all.
 *
 * Nothing in this file may reach a hosted endpoint, because the deployments it describes
 * cannot. There is no registration page, no seeded account, and no way to become an
 * administrator by signing in.
 */
class StandaloneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'local'];

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_the_sign_in_page_offers_a_password_form_and_no_provider_button(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-mode="local"', false)
            ->assertSee('data-login-url="'.route('login.store').'"', false)
            ->assertSee('data-sso-url=""', false);
    }

    public function test_correct_credentials_sign_the_person_in(): void
    {
        $user = $this->localUser();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_SUCCEEDED,
            'auth_method' => 'password',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_signing_in_grants_no_workspace_by_itself(): void
    {
        $user = $this->localUser();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->get('/dashboard')->assertOk()->assertSee('You do not belong to a workspace yet');
        $this->assertDatabaseCount('workspace_memberships', 0);
    }

    public function test_a_wrong_password_is_refused_and_recorded(): void
    {
        $user = $this->localUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['email' => 'Those credentials do not match our records.']);

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'auth_method' => 'password',
            'user_id' => $user->getKey(),
            'reason' => 'Invalid credentials',
        ]);
    }

    public function test_an_unknown_address_is_refused_with_the_same_message(): void
    {
        $this->localUser();

        // Identical to the wrong-password message, so the form cannot be used to find out
        // which addresses have accounts.
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Those credentials do not match our records.']);

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'email' => 'nobody@example.test',
            'user_id' => null,
        ]);
    }

    public function test_a_disabled_account_is_refused_even_with_the_right_password(): void
    {
        $user = $this->localUser(['disabled_at' => now()]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'This account has been disabled. Contact an operator of this installation.']);

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'reason' => 'Account disabled',
        ]);
    }

    public function test_an_account_bound_to_an_identity_provider_cannot_use_the_password_form(): void
    {
        $user = $this->localUser();
        IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => 'example-provider',
            'subject' => 'subject-one',
            'last_seen_at' => now(),
        ]);

        // Its password column holds a value nobody has ever seen, but the rule does not
        // depend on that: an account whose authentication lives at a provider must not have
        // a second, weaker door here.
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_address_shared_by_two_local_accounts_is_refused_rather_than_guessed(): void
    {
        // `users.email` carries no unique index, because email is contact data. Local login
        // resolves by address, so it must refuse when the address does not resolve to one
        // account instead of picking the lower id and choosing an identity for somebody.
        $this->localUser(['email' => 'shared@example.test']);
        $this->localUser(['email' => 'shared@example.test']);

        $this->post('/login', ['email' => 'shared@example.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'reason' => 'No unique local account for that address',
        ]);
    }

    public function test_repeated_failures_lock_the_account_out_even_once_the_password_is_right(): void
    {
        $user = $this->localUser();
        $maxAttempts = (int) config('bherila-auth.throttle.max_attempts');

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-'.$attempt]);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) session('errors')?->first('email'),
        );

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_BLOCKED,
            'auth_method' => 'password',
        ]);
    }

    public function test_signing_out_ends_the_session_locally(): void
    {
        $user = $this->localUser();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGGED_OUT,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_a_guest_is_sent_to_the_sign_in_page(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_single_sign_on_endpoints_do_not_exist(): void
    {
        $this->get('/auth/redirect')->assertNotFound();
        $this->get('/auth/callback?state=x&code=y')->assertNotFound();
        $this->post('/auth/logout')->assertNotFound();
    }

    public function test_there_is_no_registration_page(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'someone@example.test'])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function localUser(array $attributes = []): User
    {
        return User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            ...$attributes,
        ]);
    }
}
