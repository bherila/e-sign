<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Enums\AuthMode;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The sign-in page, in whichever mode this deployment runs.
 *
 * One route in both modes so `route('login')` always resolves — Laravel's `auth` middleware
 * sends guests here, and there is no useful second answer to "where do I sign in".
 * What the page offers differs: a button that starts the authorization redirect, or a
 * password form. The routes behind each of those exist in only one mode.
 */
class SignInController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        $mode = AuthMode::current();

        return view('auth.sign-in', [
            'mode' => $mode->value,
            'ssoUrl' => $mode->isSso() ? route('auth.redirect') : '',
            'loginUrl' => $mode->isStandalone() ? route('login.store') : '',
        ]);
    }
}
