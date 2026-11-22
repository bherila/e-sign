<?php

declare(strict_types=1);

namespace App\Domain\Identity\Auth;

use App\Domain\Identity\Models\IdentityBinding;
use App\Models\User;
use BWH\Auth\OAuth\Session\ProviderBinding;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The provider binding of a local account, read from `identity_bindings`.
 *
 * The package's default resolver reads `oauth_provider` / `oauth_subject` columns on the user
 * row, which this application does not have: a binding is its own row, `(issuer, subject)`,
 * written by the SSO callback, `esign:bootstrap-owner` and delegated-access provisioning. Bound
 * in AppServiceProvider for every package path that asks: RequireActiveProviderSession and the
 * local-credential policy.
 *
 * Read from the database on every call rather than from a loaded relation, so a binding changed
 * since the user was loaded is the one checked.
 *
 * - No binding: a deliberately unbound account (a standalone local administrator). Null, which
 *   leaves it to the application's own login policy.
 * - Exactly one binding under the configured provider (`oauth_client.provider`): that binding.
 * - Anything else is a broken binding and is refused, never treated as unbound: a binding under
 *   another issuer (the provider key was changed), more than one binding, or an empty subject.
 *   The SSO callback resolves on `(issuer, subject)` and creates one binding per user, so none
 *   of these arise from normal use.
 */
final class IdentityBindingResolver implements ProviderBindingResolver
{
    public function binding(Authenticatable $user): ?ProviderBinding
    {
        if (! $user instanceof User || ! $user->exists) {
            throw new ProviderSessionExpired('The provider session binding is invalid.');
        }

        $bindings = IdentityBinding::query()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->limit(2)
            ->get(['issuer', 'subject']);

        if ($bindings->isEmpty()) {
            return null;
        }

        $binding = $bindings->first();
        $provider = config('bherila-auth.oauth_client.provider');

        if ($bindings->count() > 1
            || ! is_string($provider) || $provider === ''
            || $binding->issuer !== $provider
            || $binding->subject === '') {
            throw new ProviderSessionExpired('The provider session binding is invalid.');
        }

        return new ProviderBinding($binding->issuer, $binding->subject);
    }
}
