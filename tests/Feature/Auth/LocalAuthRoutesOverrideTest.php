<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ESIGN_LOCAL_AUTH_ROUTES` overrides the automatic choice `config/bherila-auth.php` makes
 * from the resolved auth mode. `auto` (the default, covered in OAuthLoginTest and
 * StandaloneLoginTest) is not exercised here — only `on` pushed against SSO mode, the one
 * combination that otherwise disagrees with it.
 */
class LocalAuthRoutesOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected array $envOverrides = [
        'ESIGN_AUTH_MODE' => 'sso',
        'ESIGN_LOCAL_AUTH_ROUTES' => 'on',
    ];

    public function test_on_registers_the_local_auth_routes_even_in_sso_mode(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'someone@example.test'])
            ->assertStatus(200);
    }
}
