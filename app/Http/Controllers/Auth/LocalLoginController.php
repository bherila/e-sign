<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use BWH\Auth\Concerns\LogsAuthEvents;
use BWH\Auth\Concerns\ThrottlesLoginAttempts;
use BWH\Auth\Contracts\AuthUserPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Standalone password login, for installations with no identity provider.
 *
 * Registered only in standalone mode, so an SSO deployment has no password endpoint to
 * attack. There is no registration page and no seeded account: the first local user is
 * created by `esign:create-user` and made an owner by `esign:bootstrap-owner`.
 *
 * The package does not own primary credential login — each application's admission rules
 * differ — so the lockout and the audit trail are called from here. Both are the package's,
 * not a second implementation: `auth_audit_log` is the same table the passkey, two-factor,
 * and password-reset flows write to, and the throttle reads its counts back out of it.
 */
class LocalLoginController extends Controller
{
    use LogsAuthEvents;
    use ThrottlesLoginAttempts;

    private const AUTH_METHOD = 'password';

    /**
     * One message for every failure.
     *
     * A wrong password, an address with no account, and an address that somehow matches two
     * must be indistinguishable from outside, or the form becomes a way to find out which
     * addresses have accounts. The audit row records which it actually was.
     */
    private const GENERIC_FAILURE = 'Those credentials do not match our records.';

    public function store(LoginRequest $request, AuthUserPolicy $policy): RedirectResponse
    {
        $email = $request->credentialEmail();

        $throttle = $this->inspectLoginThrottle($request, null, $email, self::AUTH_METHOD);

        if ($throttle->locked) {
            $this->auditLoginBlocked($request, null, $email, self::AUTH_METHOD, $throttle);

            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Try again in '.$throttle->availableInSeconds().' seconds.',
            ]);
        }

        $user = $this->resolveLocalAccount($email);

        if ($user === null) {
            $this->auditLoginFailed($request, null, $email, 'No unique local account for that address', self::AUTH_METHOD);

            throw ValidationException::withMessages(['email' => self::GENERIC_FAILURE]);
        }

        if (! Hash::check($request->credentialPassword(), (string) $user->password)) {
            $this->auditLoginFailed($request, $user, $email, 'Invalid credentials', self::AUTH_METHOD);

            throw ValidationException::withMessages(['email' => self::GENERIC_FAILURE]);
        }

        // Checked after the password, so account state is only ever disclosed to somebody
        // who already holds the credential. They are told plainly at that point: a disabled
        // account that reports "wrong password" sends its owner to reset a password that
        // was never the problem.
        if (! $policy->canLogin($user, $request)) {
            $this->auditLoginFailed($request, $user, $email, 'Account disabled', self::AUTH_METHOD);

            throw ValidationException::withMessages([
                'email' => 'This account has been disabled. Ask a workspace owner to restore it.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->auditLoginSucceeded($request, $user, self::AUTH_METHOD);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->auditLoggedOut($request, $user);

        return redirect()->route('login');
    }

    /**
     * The one local account that address may sign in as, or none.
     *
     * Two conditions, both of which follow from email not being an identity key. Users bound
     * to an identity provider are excluded outright — their password column holds a random
     * value nobody has ever seen, and admitting them here would be a second, weaker door
     * into an account whose real authentication lives at the provider. And because
     * `users.email` has no unique index, an address that matches more than one row is
     * refused rather than guessed at: picking the lowest id would be choosing an identity
     * for somebody.
     */
    private function resolveLocalAccount(string $email): ?User
    {
        $candidates = User::query()
            ->where('email', $email)
            ->whereDoesntHave('identityBindings')
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
