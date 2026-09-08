<?php

use App\Http\Controllers\Mail\BrevoWebhookController;
use App\Http\Controllers\Mail\SesWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider mail feedback (Stage 4, issue #35)
|--------------------------------------------------------------------------
|
| NOT YET REGISTERED. bootstrap/app.php is owned by another change in flight,
| so this file is not yet in its `then:` closure. To turn these endpoints on,
| add alongside the existing entries there:
|
|     Route::group([], base_path('routes/mail-webhooks.php'));
|
| Until that line exists these routes do not resolve, and the outbox works
| without them: provider feedback is what upgrades `sent_to_provider` to
| `delivered` or `bounced`, so without it messages simply stay at
| `sent_to_provider`, which remains an honest statement of what is known.
|
| No `web` middleware, and that is the point of a separate file: these are
| machine callers with no session and no CSRF token, and putting them in
| routes/web.php would either break them or weaken the session stack for
| everyone. No `auth` either — each endpoint authenticates its own caller,
| which for Brevo is a shared token in the Form Request and for SES is an SNS
| signature that is currently refused outright.
|
| Both are rate limited. A webhook endpoint is a public POST surface, and the
| work behind it writes rows.
|
*/

Route::middleware('throttle:120,1')
    ->prefix('webhooks/mail')
    ->name('webhooks.mail.')
    ->group(function (): void {
        // Brevo transactional events: delivered, hardBounce, softBounce, blocked, spam,
        // deferred, error. Authenticated by the shared token in
        // config('esign.mail.brevo_webhook_token'), because Brevo signs nothing.
        Route::post('/brevo', BrevoWebhookController::class)->name('brevo');

        // SES Delivery/Bounce/Complaint via SNS. Rejects every message until an SNS
        // signature verifier is implemented; see the controller.
        Route::post('/ses', SesWebhookController::class)->name('ses');
    });
