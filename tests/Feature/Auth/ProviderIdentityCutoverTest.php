<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use BWH\Auth\OAuth\Session\ProviderIdentityEnforcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakesProviderIdentity;
use Tests\TestCase;

/**
 * Once eSign records its provider identity cutover, enforcement stays on even if
 * BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED is later false, and the deploy check refuses a release
 * whose configuration disagrees (docs/operations/provider-identity.md).
 */
class ProviderIdentityCutoverTest extends TestCase
{
    use FakesProviderIdentity;
    use RefreshDatabase;

    protected array $envOverrides = ['ESIGN_AUTH_MODE' => 'sso'];

    private const SUBJECT = 'd5e6f0de-0000-4000-8000-000000000001';

    public function test_the_cutover_table_is_migrated_and_nothing_is_recorded_by_default(): void
    {
        $this->assertTrue(Schema::hasTable(ProviderIdentityEnforcement::TABLE));

        $this->fakeProviderIdentity(enforced: false);
        $this->assertFalse(app(ProviderIdentityEnforcement::class)->enabled());
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertSuccessful();
    }

    public function test_record_refuses_while_the_setting_is_off(): void
    {
        $this->fakeProviderIdentity(enforced: false);

        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'record'])->assertFailed();
        $this->assertDatabaseCount(ProviderIdentityEnforcement::TABLE, 0);
    }

    public function test_a_recorded_cutover_keeps_enforcement_on_with_the_setting_off(): void
    {
        $this->fakeProviderIdentity();
        $this->boundUser(self::SUBJECT);
        $this->providerIdentity(self::SUBJECT);
        $this->signInThroughProvider(self::SUBJECT, 1)->assertRedirect(route('dashboard'));
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'record'])->assertSuccessful();

        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->assertTrue(app(ProviderIdentityEnforcement::class)->enabled());

        // The provider ends the identity: the browser session is still signed out.
        $this->disableProviderIdentity(self::SUBJECT);
        $this->travel(6)->minutes();
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        // And a sign-in that cannot be verified is still refused.
        $this->signInThroughProvider(self::SUBJECT, null)->assertStatus(503);
        $this->assertGuest();
    }

    public function test_the_deploy_check_fails_when_the_setting_disagrees_with_a_recorded_cutover(): void
    {
        $this->fakeProviderIdentity();
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'record'])->assertSuccessful();
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertSuccessful();

        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertFailed();

        config([
            'bherila-auth.provider_identity.enabled' => true,
            'bherila-auth.oauth_client.client_secret' => null,
        ]);
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertFailed();
    }

    public function test_a_withdrawn_cutover_hands_enforcement_back_to_the_setting(): void
    {
        $this->fakeProviderIdentity();
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'record'])->assertSuccessful();
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'withdraw', '--force' => true])->assertSuccessful();

        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->assertFalse(app(ProviderIdentityEnforcement::class)->enabled());
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertSuccessful();
    }
}
