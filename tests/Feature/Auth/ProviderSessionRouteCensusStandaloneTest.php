<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

/**
 * The route census in standalone mode, where the password form, password reset, passkeys and
 * two-factor routes are registered by default and the SSO callback is absent.
 */
class ProviderSessionRouteCensusStandaloneTest extends ProviderSessionRouteCensusTest
{
    protected array $envOverrides = [
        'ESIGN_AUTH_MODE' => 'local',
        'BHERILA_AUTH_AUDIT_ROUTES' => 'true',
    ];
}
