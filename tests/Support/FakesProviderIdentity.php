<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\IdentityBinding;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * A synthetic identity provider for provider identity enforcement tests: the sign-in callback's
 * token and identity endpoints and the identity-status endpoint, on a reserved test host.
 *
 * Each person is a subject with a credential generation, which a test can reset (a new
 * generation, as a password reset at the provider does), disable (inactive) or take offline
 * (the status endpoint answers 503). Status requests are counted, so a test can say the
 * provider was never asked.
 *
 * The using test must run in SSO mode (`$envOverrides = ['ESIGN_AUTH_MODE' => 'sso']`) for
 * the callback route to exist.
 */
trait FakesProviderIdentity
{
    private const PROVIDER = 'example-provider';

    private const PROVIDER_URL = 'https://identity.example.test';

    /** @var array<string, int|null> subject => credential generation, null when inactive */
    private array $providerIdentities = [];

    private bool $providerOffline = false;

    private int $providerStatusRequests = 0;

    /** @var array<string, mixed>|null The provider's sign-in identity response for the next callback. */
    private ?array $providerLoginIdentity = null;

    protected function fakeProviderIdentity(bool $enforced = true): void
    {
        config([
            'bherila-auth.oauth_client' => [
                'provider' => self::PROVIDER,
                'base_url' => self::PROVIDER_URL,
                'client_id' => 'esign-client',
                'client_secret' => 'synthetic-client-secret',
                'redirect_uri' => 'http://localhost/auth/callback',
                'scope' => 'identity:read',
                'authorize_path' => '/oauth/authorize',
                'token_path' => '/oauth/token',
                'identity_path' => '/api/oauth/user',
                'end_session_path' => '/oauth/end-session',
            ],
            'bherila-auth.provider_identity.enabled' => $enforced,
        ]);

        Http::fake(function (ClientRequest $request) {
            if ($request->url() === self::PROVIDER_URL.'/api/reconciliation/identity-status') {
                $this->providerStatusRequests++;
                if ($this->providerOffline) {
                    return Http::response(['message' => 'unavailable'], 503);
                }
                $subject = (string) ($request->data()['subject'] ?? '');
                $generation = $this->providerIdentities[$subject] ?? null;

                return Http::response($generation === null
                    ? ['contract_version' => 1, 'active' => false]
                    : ['contract_version' => 1, 'active' => true, 'subject' => $subject, 'credential_version' => $generation]);
            }
            if ($request->url() === self::PROVIDER_URL.'/oauth/token') {
                return Http::response(['access_token' => 'synthetic-provider-access-token']);
            }
            if ($request->url() === self::PROVIDER_URL.'/api/oauth/user') {
                return Http::response($this->providerLoginIdentity ?? []);
            }

            return Http::response(['message' => 'not found'], 404);
        });
    }

    /** A person the provider knows, active at this generation. */
    protected function providerIdentity(string $subject, ?int $generation = 1): void
    {
        $this->providerIdentities[$subject] = $generation;
    }

    protected function disableProviderIdentity(string $subject): void
    {
        $this->providerIdentities[$subject] = null;
    }

    protected function takeProviderOffline(bool $offline = true): void
    {
        $this->providerOffline = $offline;
    }

    /** A local account bound to the provider subject, as the SSO callback leaves it. */
    protected function boundUser(string $subject): User
    {
        $user = User::factory()->create([
            'name' => 'Bound '.$subject,
            'email' => $subject.'@example.test',
        ]);
        IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => self::PROVIDER,
            'subject' => $subject,
            'last_seen_at' => now(),
        ]);

        return $user;
    }

    /**
     * Sign in through the real callback, as the provider reports the person at sign-in: this
     * records the session's login generation. Null sends an identity response without one, as
     * an older provider does.
     */
    protected function signInThroughProvider(string $subject, ?int $generation): TestResponse
    {
        $this->providerLoginIdentity = array_filter([
            'sub' => $subject,
            'name' => 'Bound '.$subject,
            'email' => $subject.'@example.test',
            'credential_version' => $generation,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->withSession([
            'oauth.login.state' => 'provider-state',
            'oauth.login.code_verifier' => 'provider-verifier',
        ])->get('/auth/callback?state=provider-state&code=provider-code');
    }
}
