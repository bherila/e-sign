<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Identity\Enums\AuthMode;
use BWH\Auth\OAuth\OAuthClient;
use Tests\TestCase;

/**
 * A fresh install ships no provider hostname.
 *
 * `config/bherila-auth.php`'s `oauth_client.base_url` used to fall back to this package's
 * original operator's own domain when `OAUTH_PROVIDER_URL` was unset. That made every
 * unconfigured install SSO-shaped by accident: `OAuthClient::isConfigured()` only checks
 * `client_id` and `base_url` are non-empty, and a real-looking default for one of the two
 * hides the missing setting instead of surfacing it. This is public OSS, so the default
 * must resolve to nothing at all.
 */
class OAuthClientDefaultConfigurationTest extends TestCase
{
    public function test_a_fresh_configuration_resolves_to_standalone_and_an_unconfigured_client(): void
    {
        // .env.testing (like .env.example) sets none of the four OAUTH_* variables, so this
        // exercises the published config's actual defaults rather than a value this test set.
        $this->assertSame('', config('bherila-auth.oauth_client.base_url'));
        $this->assertFalse(OAuthClient::isConfigured());
        $this->assertSame(AuthMode::Standalone, AuthMode::fromSetting('auto'));
    }

    public function test_setting_the_four_oauth_variables_flips_the_resolved_mode_to_sso(): void
    {
        config([
            'bherila-auth.oauth_client' => [
                'provider' => 'acme',
                'base_url' => 'https://identity.example.test',
                'client_id' => 'esign-client',
                'client_secret' => 'esign-secret',
                'redirect_uri' => 'http://localhost/auth/callback',
                'scope' => 'identity:read',
                'authorize_path' => '/oauth/authorize',
                'token_path' => '/oauth/token',
                'identity_path' => '/api/oauth/user',
                'end_session_path' => '/oauth/end-session',
            ],
        ]);

        $this->assertTrue(OAuthClient::isConfigured());
        $this->assertSame(AuthMode::Sso, AuthMode::fromSetting('auto'));
    }
}
