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
use BWH\Auth\OAuth\Session\ProviderSession;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
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

        // Refuse an explicitly unverified address before provisioning or refreshing a
        // subject binding. Providers omitting the claim retain their legacy behavior.
        if ($identity->emailVerified === false && (bool) config('bherila-auth.oauth_login.refuse_unverified_email', true)) {
            $this->auditLoginFailed($request, null, null, 'Identity email not verified by provider', self::AUTH_METHOD);

            abort(403, 'The identity provider has not verified this email address.');
        }

        // Strict proof (oauth_login.require_verified_email, off by default): only the JSON
        // boolean true passes, so an absent claim and a loosely spelled "true" are refused too.
        // The same rule as the package's RelyingPartyLoginController, which this controller
        // does not extend.
        if (! $identity->emailVerifiedNative && (bool) config('bherila-auth.oauth_login.require_verified_email', false)) {
            $this->auditLoginFailed($request, null, null, 'Identity email not proven by provider', self::AUTH_METHOD);

            abort(403, 'The identity provider has not verified this email address.');
        }

        // Resolved on (issuer, subject). Never on the email address, however convenient the
        // provider has just made it.
        $user = $identities->resolve($identity);

        if (! $policy->canLogin($user, $request)) {
            $this->auditLoginFailed($request, $user, $identity->email, 'Account disabled', self::AUTH_METHOD);

            abort(403, 'This account has been disabled. Contact an operator of this installation.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        // The provider credential generation this login was made under: the baseline every
        // later RequireActiveProviderSession check of this session compares against. Recorded
        // while enforcement is off too, so turning enforcement on does not end every session
        // at once. With enforcement on (the setting, or a recorded cutover), a login that
        // cannot be verified has already been undone by the package (logout, new session, new
        // CSRF token) and the person retries; docs/operations/provider-identity.md.
        try {
            app(ProviderSession::class)->establish($request, $identity, Auth::guard());
        } catch (ProviderStatusUnavailable) {
            $this->auditLoginFailed($request, $user, $identity->email, 'Provider session unverified', self::AUTH_METHOD);

            abort(503, 'Sign-in verification is unavailable. Please retry.', [
                'Retry-After' => '30',
                'Cache-Control' => 'private, no-store',
            ]);
        }

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
