<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use BWH\Auth\Mail\TwoFactorLoginMail;
use BWH\Auth\Models\TwoFactorAttempt;
use BWH\Auth\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Auth030AdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'local'];

    public function test_enrollment_requires_recent_confirmation_and_recovers_after_password_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password')]);
        $this->actingAs($user)->postJson('/api/passkeys/register/options')
            ->assertStatus(423)->assertJsonPath('error', 'password_confirmation_required');
        $this->postJson('/api/auth/confirm-password', ['password' => 'wrong-password'])->assertUnprocessable();
        $this->postJson('/api/passkeys/register/options')->assertStatus(423);
        $this->postJson('/api/auth/confirm-password', ['password' => 'current-password'])->assertOk();
        $this->postJson('/api/passkeys/register/options')->assertOk()->assertJsonStructure(['challenge']);
        $this->travel((int) config('auth.password_timeout', 10800) + 1)->seconds();
        $this->postJson('/api/passkeys/register/options')->assertStatus(423);
    }

    public function test_email_link_requires_a_separate_hashed_secret(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $request = Request::create('https://esign.example.test/login', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        app(TwoFactorService::class)->startChallenge($user, $request);
        $attempt = TwoFactorAttempt::query()->sole();

        $this->postJson('/api/auth/two-factor/confirm/'.$attempt->token)->assertUnprocessable();
        $this->assertGuest();
        Mail::assertSent(TwoFactorLoginMail::class, function (TwoFactorLoginMail $mail) use ($attempt): bool {
            parse_str((string) parse_url((string) $mail->confirmUrl, PHP_URL_QUERY), $query);
            $secret = $query['secret'] ?? '';
            $this->assertSame(64, strlen($secret));
            $this->assertSame(hash('sha256', $secret), $attempt->link_secret_hash);

            return true;
        });
    }

    public function test_stale_password_hash_session_is_rejected_before_dashboard_authorization(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $user->forceFill(['password' => Hash::make('replacement-password')])->save();

        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])
            ->getJson('/dashboard')->assertUnauthorized();
        $this->assertGuest();
    }
}
