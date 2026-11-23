<?php

declare(strict_types=1);

namespace App\Domain\Identity\Auth;

use BWH\Auth\Contracts\LocalCredentialPolicy;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderIdentityEnforcement;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use BWH\Auth\Services\ConfiguredLocalCredentialPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Whether an account may sign in with, or acquire, a credential this application holds itself:
 * the package's password reset, change and confirmation, passkey and two-factor paths.
 *
 * While provider identity enforcement is on (the setting, or a recorded cutover), an account
 * bound to the identity provider has no local credential path at all. A local sign-in carries
 * no provider login baseline, so RequireActiveProviderSession would end it on the next request
 * anyway; refusing it up front says why instead of signing the person in and straight back
 * out. More importantly, a local credential is exactly what must not outlive the provider
 * disabling the person, resetting them or removing their grant.
 *
 * Unbound accounts (standalone local administrators) keep their credentials. A binding the
 * resolver calls broken is refused, never treated as unbound.
 *
 * The standalone password form (LocalLoginController) does not need this: it already excludes
 * every account that has an identity binding, whatever enforcement says.
 *
 * With enforcement off this is the package's ConfiguredLocalCredentialPolicy unchanged
 * (`bherila-auth.local_credentials.provider_bound`, default 'allow').
 */
final readonly class EsignLocalCredentialPolicy implements LocalCredentialPolicy
{
    public function __construct(
        private ConfiguredLocalCredentialPolicy $configured,
        private ProviderBindingResolver $bindings,
        private ProviderIdentityEnforcement $enforcement,
    ) {}

    public function mayUseLocalCredentials(Authenticatable $user, Request $request): bool
    {
        if (! $this->enforcement->enabled()) {
            return $this->configured->mayUseLocalCredentials($user, $request);
        }

        try {
            return $this->bindings->binding($user) === null;
        } catch (ProviderSessionExpired) {
            return false;
        }
    }
}
