<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Auth\EsignLocalCredentialPolicy;
use App\Domain\Identity\Models\IdentityBinding;
use App\Models\User;
use BWH\Auth\Contracts\LocalCredentialPolicy;
use BWH\Auth\Models\AuthAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The local sign-in policy under provider identity enforcement (docs/operations/provider-identity.md).
 *
 * A local sign-in carries no provider login baseline, so an account bound to the identity
 * provider must not get one while enforcement is on: it would be signed straight back out, and a
 * local credential is exactly what must not outlive the provider ending the person. The password
 * form already refuses every bound account, whatever enforcement says; the package's password
 * reset (and passkey and two-factor paths) refuse them through EsignLocalCredentialPolicy while
 * enforcement is on, and behave exactly as before while it is off.
 *
 * Standalone mode, because that is where these routes exist by default.
 */
class ProviderIdentityLocalCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'local'];

    private const PASSWORD = 'correct-horse-battery-staple';

    private const NEW_PASSWORD = 'a-different-synthetic-passphrase';

    protected function setUp(): void
    {
        parent::setUp();

        config(['bherila-auth.oauth_client.provider' => 'example-provider']);
        Mail::fake();
    }

    public function test_the_package_asks_the_applications_local_credential_policy(): void
    {
        $this->assertInstanceOf(EsignLocalCredentialPolicy::class, app(LocalCredentialPolicy::class));
    }

    public function test_with_enforcement_on_a_bound_account_still_cannot_use_the_password_form(): void
    {
        $this->enforce();
        $user = $this->boundUser();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => AuthAuditLog::EVENT_LOGIN_FAILED,
            'auth_method' => 'password',
            'reason' => 'No unique local account for that address',
        ]);
    }

    public function test_with_enforcement_on_a_bound_account_cannot_reset_a_local_password(): void
    {
        $this->enforce();
        $user = $this->boundUser();

        $this->resetPassword($user)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This account signs in through single sign-on.');

        $this->assertGuest();
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $user->fresh()->password));
    }

    public function test_with_enforcement_off_the_reset_policy_is_the_packages_unchanged(): void
    {
        config(['bherila-auth.provider_identity.enabled' => false]);
        $user = $this->boundUser();

        // 'allow' is the package default and this installation's until an operator changes it.
        $this->resetPassword($user)->assertOk()->assertJsonPath('success', true);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $user->fresh()->password));
    }

    public function test_with_enforcement_off_the_refuse_setting_uses_identity_bindings(): void
    {
        config([
            'bherila-auth.provider_identity.enabled' => false,
            'bherila-auth.local_credentials.provider_bound' => 'refuse',
        ]);
        $bound = $this->boundUser();
        $unbound = $this->localUser();

        $this->resetPassword($bound)->assertUnprocessable();
        $this->resetPassword($unbound)->assertOk();
    }

    public function test_an_unbound_local_administrator_is_unaffected_by_enforcement(): void
    {
        // Enforcement on with no identity provider configured at all: nothing an unbound
        // account does may depend on the provider.
        $this->enforce();
        $user = $this->localUser();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();
        $this->postJson('/api/auth/confirm-password', ['password' => self::PASSWORD])->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(app(LocalCredentialPolicy::class)->mayUseLocalCredentials($user, request()));
    }

    private function enforce(): void
    {
        config(['bherila-auth.provider_identity.enabled' => true]);
    }

    private function localUser(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    private function boundUser(): User
    {
        $user = $this->localUser();
        IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => 'example-provider',
            'subject' => 'c3d4e0de-0000-4000-8000-000000000001',
            'last_seen_at' => now(),
        ]);

        return $user;
    }

    private function resetPassword(User $user): TestResponse
    {
        return $this->postJson('/api/auth/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
    }
}
