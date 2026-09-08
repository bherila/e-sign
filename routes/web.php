<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\AuthMode;
use App\Http\Controllers\Auth\LocalLoginController;
use App\Http\Controllers\Auth\OAuthLoginController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Exactly one mode's routes are registered. The other mode's endpoints do not
| exist — not disabled, not guarded, absent — so a standalone installation has
| no OAuth callback to probe and an SSO installation has no password endpoint
| to attack. `php artisan route:clear` after changing ESIGN_AUTH_MODE.
|
| There is no registration route in either mode, by design: accounts are created
| by `esign:create-user` and given authority by `esign:bootstrap-owner`.
|
*/

Route::get('/login', [SignInController::class, 'show'])->name('login');

if (AuthMode::current()->isSso()) {
    Route::get('/auth/redirect', [OAuthLoginController::class, 'redirect'])->name('auth.redirect');
    Route::get('/auth/callback', [OAuthLoginController::class, 'callback'])->name('auth.callback');

    // Outside `auth` middleware on purpose: an already-expired session posting here should
    // still be sent through the provider's end-session endpoint, not bounced to sign in.
    Route::post('/auth/logout', [OAuthLoginController::class, 'logout'])->name('logout');
} else {
    Route::post('/login', [LocalLoginController::class, 'store'])->name('login.store');
    Route::post('/logout', [LocalLoginController::class, 'destroy'])->name('logout');
}

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
