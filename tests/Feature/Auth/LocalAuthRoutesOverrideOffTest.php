<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * The other half of the `ESIGN_LOCAL_AUTH_ROUTES` override: `off` against standalone mode,
 * the one combination that otherwise disagrees with it. See LocalAuthRoutesOverrideTest.
 */
class LocalAuthRoutesOverrideOffTest extends TestCase
{
    protected array $envOverrides = [
        'ESIGN_AUTH_MODE' => 'local',
        'ESIGN_LOCAL_AUTH_ROUTES' => 'off',
    ];

    public function test_off_removes_the_local_auth_routes_even_in_standalone_mode(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'someone@example.test'])
            ->assertNotFound();
    }
}
