<?php

declare(strict_types=1);

namespace App\Domain\Identity\Auth;

use App\Models\User;
use BWH\Auth\Services\DefaultAuthUserPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The single gate for "is this account allowed to complete a login".
 *
 * Bound over the package's default in AppServiceProvider so that every entry point shares
 * one answer: the SSO callback, the standalone password form, the package's passkey and
 * two-factor paths, and its `RequireActiveUser` middleware all resolve this contract. A
 * check written into one controller would be a check the other three do not make.
 *
 * The rule is narrow on purpose. Being allowed to log in is not being allowed to do
 * anything: a person with no workspace membership authenticates successfully and then has
 * no ability at all (see WorkspacePolicy). Admission and authority are separate, and this
 * class only decides admission.
 */
class EsignUserPolicy extends DefaultAuthUserPolicy
{
    public function canLogin(Authenticatable $user, Request $request): bool
    {
        if (! $user instanceof User) {
            // Not a shape this application issues sessions for. Refusing is the only safe
            // reading; the default policy's "assume allowed" fallback is for apps that have
            // no account-state column, and this one has.
            return false;
        }

        return ! $user->isDisabled();
    }
}
