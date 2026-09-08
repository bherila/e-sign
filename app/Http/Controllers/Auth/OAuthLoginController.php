<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Services\IdentityResolver;
use App\Http\Controllers\Controller;
use BWH\Auth\Concerns\LogsAuthEvents;
use BWH\Auth\Concerns\SignsOutThroughProvider;
use BWH\Auth\Contracts\AuthUserPolicy;
use BWH\Auth\OAuth\OAuthClient;
use BWH\Auth\OAuth\ProviderApplications;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Administrator single sign-on.
 *
 * The package owns state, PKCE, the code exchange, and validation of the provider's
 * identity response — everything that is the same for every relying party and dangerous to
 * reimplement. What is left here is what only this application can decide: which local user
 * a provider subject is, whether that account may sign in, and where they land afterwards.
 *
 * Registered only in SSO mode (see routes/web.php), so a standalone installation has no
 * callback endpoint at all rather than one that answers with an error.
 */
class OAuthLoginController extends Controller
{
    use LogsAuthEvents;
    use SignsOutThroughProvider;

    /**
     * How this login is described in the authentication audit trail. Distinct from
     * `password` so a credential lockout can never be triggered or reset by an SSO login.
     */
    private const AUTH_METHOD = 'oauth';

    public function redirect(Request $request, OAuthClient $oauth): RedirectResponse
    {
        return $oauth->redirect($request);
    }

    public function callback(
        Request $request,
        OAuthClient $oauth,
        IdentityResolver $identities,
        AuthUserPolicy $policy,
    ): RedirectResponse {
        // Verifies state, redeems the code with the PKCE verifier, and validates the
        // identity response. The state and verifier are pulled out of the session, so a
        // replayed callback finds nothing to compare against and is refused.
        $identity = $oauth->identityFromCallback($request);

        // Resolved on (issuer, subject). Never on the email address, however convenient the
        // provider has just made it.
        $user = $identities->resolve($identity);

        if (! $policy->canLogin($user, $request)) {
            $this->auditLoginFailed($request, $user, $identity->email, 'Account disabled', self::AUTH_METHOD);

            abort(403, 'This account has been disabled. Contact an operator of this installation.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Only now, once the application has decided to admit this person. A sign-in that
        // was refused leaves nothing behind in the visitor's session.
        ProviderApplications::remember($request, $identity->apps);

        $this->auditLoginSucceeded($request, $user, self::AUTH_METHOD);

        // Somebody whose subject is new has no workspace membership; the dashboard is where
        // that is explained. Nothing here grants one.
        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, OAuthClient $oauth): RedirectResponse
    {
        // Ending only the local session would leave the provider still recognising this
        // person, so the next protected page would hand them straight back without a
        // prompt — a sign-out button that visibly does nothing.
        return $this->signOutThroughProvider($request, $oauth);
    }

    protected function afterLocalSignOut(Request $request, ?Authenticatable $user): void
    {
        $this->auditLoggedOut($request, $user);
    }
}
