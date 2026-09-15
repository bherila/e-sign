<?php

declare(strict_types=1);

use App\Http\Controllers\DelegatedAccess\ApplicationAccessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Delegated application access (issue #111)
|--------------------------------------------------------------------------
|
| Called by the identity provider's server, never by a browser. No `web` group,
| so no session, no CSRF and no cookies; and no `service-credential`, because
| the caller proves who is acting with a signed, single-use actor assertion
| that the controller verifies before anything else. There is deliberately no
| OAuth-token or API-key fallback on this route. Off unless
| ESIGN_DELEGATED_ACCESS_ENABLED is set; see docs/operations/delegated-access.md.
|
*/

Route::post('/application-access', ApplicationAccessController::class)
    ->middleware('throttle:delegated-access')
    ->name('delegated-access');
