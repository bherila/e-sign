<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Enums\AuthMode;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Which sign-in surface a deployment exposes, and how it is decided.
 *
 * The answer chooses which routes exist, so getting it wrong is not a cosmetic mistake:
 * `auto` on a deployment whose OAuth settings failed to load would serve a password form
 * where the operator expected a provider. `sso` exists so that deployment can say no.
 */
class AuthModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['bherila-auth.oauth_client.base_url' => 'https://identity.example.test']);
    }

    public function test_auto_follows_whether_an_oauth_client_has_been_issued(): void
    {
        config(['bherila-auth.oauth_client.client_id' => 'esign-client']);
        $this->assertSame(AuthMode::Sso, AuthMode::fromSetting('auto'));

        config(['bherila-auth.oauth_client.client_id' => '']);
        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting('auto'));
    }

    public function test_an_empty_setting_behaves_like_auto(): void
    {
        config(['bherila-auth.oauth_client.client_id' => '']);

        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting(''));
        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting(null));
    }

    public function test_local_wins_over_a_configured_oauth_client(): void
    {
        config(['bherila-auth.oauth_client.client_id' => 'esign-client']);

        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting('local'));
        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting(' LOCAL '));
        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting('standalone'));
    }

    public function test_sso_does_not_fall_back_to_a_password_form_when_the_client_is_missing(): void
    {
        config(['bherila-auth.oauth_client.client_id' => '']);

        // A deployment that means to use a provider wants an outage here, not a login form
        // that happens to work.
        $this->assertSame(AuthMode::Sso, AuthMode::fromSetting('sso'));
    }

    public function test_an_unrecognised_setting_is_an_error_rather_than_a_guess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ESIGN_AUTH_MODE');

        AuthMode::fromSetting('single-sign-on');
    }

    public function test_the_configured_default_is_auto(): void
    {
        $this->assertSame('auto', config('esign.auth_mode'));
    }
}
