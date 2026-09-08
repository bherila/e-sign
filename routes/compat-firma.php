<?php

declare(strict_types=1);

use App\Domain\Integration\Firma\FirmaProfile;
use App\Http\Controllers\Compat\Firma\SigningRequestController;
use App\Http\Controllers\Compat\Firma\SigningRequestDownloadController;
use App\Http\Controllers\Compat\Firma\SigningRequestFieldController;
use App\Http\Controllers\Compat\Firma\SigningRequestUserController;
use App\Http\Controllers\Compat\Firma\UnsupportedEndpointController;
use App\Http\Middleware\Compat\FirmaErrorBoundary;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Firma-compatible facade — profile `firma-compat-v1`
|--------------------------------------------------------------------------
|
| Base path `/functions/v1/signing-request-api`, which is upstream's `servers[0]`
| verbatim. Contract: docs/api/firma-compat-v1.md. Capability matrix, including
| every intentional difference and every unsupported option:
| docs/compatibility/firma-capability-matrix.md.
|
| Loaded by App\Providers\IntegrationServiceProvider rather than from
| routes/api.php, because bootstrap/app.php mounts that file under the `api`
| prefix and this base path is not ours to move (see the header of routes/api.php).
|
| Five things are decided here rather than anywhere else.
|
| 1. **Who the caller is.** `service-credential` accepts the API key in
|    `Authorization` with **no scheme at all**, which is how the consumer of this
|    profile sends it, as well as with `Bearer`. docs/HANDOFF.md section 10 makes
|    the raw form a requirement: rejecting it would mean either patching every
|    consumer or shipping a facade that is not compatible.
|
| 2. **That the caller is admitted to this surface at all.**
|    `require-scope:compat:firma-v1` is on the whole group. It is a separate
|    authority from every resource scope and grants none of them
|    (App\Domain\Identity\Credentials\Scope): a credential issued for the native
|    API does not silently gain a second HTTP surface, and a credential admitted
|    here still needs `envelopes:read` to read and `envelopes:write` to write.
|
| 3. **What each route may do.** The resource scope is named beside the route, so
|    an endpoint's authority is readable here instead of inside a controller.
|
| 4. **Which tenant it sees.** There is no workspace in any of these paths and
|    none accepted in any body. The credential's workspace constrains every
|    lookup *before* the `{id}` from the URL is used, which is why another
|    tenant's id is a 404 here and never a 403 (docs/HANDOFF.md section 10).
|
| 5. **What a failure looks like.** `FirmaErrorBoundary` is the outermost
|    middleware, so every error leaves in the upstream envelope
|    (`{"error", "message", "details"}`) rather than the native API's
|    (`{"error": {"code", ...}}`). Two surfaces, two contracts; neither inherits
|    the other's (docs/HANDOFF.md section 10).
|
| `{id}` is not constrained to a ULID even though that is what this application
| issues. The recorded fixtures include an id the consumer had stored that was
| never a valid provider id at all, and upstream answers 404 for it on every
| endpoint; a router-level pattern would answer 404 too, but through the global
| handler and in the wrong body. Letting it reach the lookup keeps every unknown
| id — malformed, foreign, or simply absent — answering the one 404 this surface
| documents.
|
*/

Route::prefix(FirmaProfile::BASE_PATH)
    ->name('compat.firma.')
    ->middleware([FirmaErrorBoundary::class])
    ->group(function (): void {

        /*
         * The bytes behind a `download_url`.
         *
         * Declared before the authenticated group and deliberately outside it: this is the
         * one route on the facade with no API key, because the JSON `/download` body promises
         * a URL a consumer can simply follow — often from a browser or a worker that holds no
         * credential. Its authority is the signature Laravel's `signed` middleware verifies,
         * which covers the token and the expiry together so neither can be edited, and the
         * token names one document of one signing request and nothing else.
         *
         * The bytes still stream through this application, on every driver. Nothing is
         * pre-signed and no disk name or object path appears in the link
         * (docs/BLOB_STORAGE.md rule 1). App\Domain\Integration\Firma\DownloadUrlIssuer sets
         * out the whole trade and how narrow the resulting capability is.
         */
        Route::get('/downloads/{token}', [SigningRequestDownloadController::class, 'stream'])
            ->middleware('signed')
            ->where('token', '[A-Za-z0-9_-]{1,512}')
            // The name is a contract with App\Domain\Integration\Firma\DownloadUrlIssuer::ROUTE,
            // which mints the links; tests/Feature/Integration/Firma/FirmaDownloadTest asserts
            // the two agree, because a rename that missed one would mint links to nothing.
            ->name('downloads.show');

        Route::middleware(['service-credential', 'require-scope:'.FirmaProfile::SCOPE])
            ->prefix('signing-requests')
            ->name('signing-requests.')
            ->group(function (): void {

                /*
                 * `templates:read` is **not** named here, and that is deliberate. Both create
                 * routes accept either a `template_id` or an inline `document`, so whether a
                 * call reads a template is a property of the body rather than of the
                 * endpoint. Naming the scope on one route and not the other would leave the
                 * gate bypassable through the other; naming it on both would demand a
                 * template scope of a caller that only ever posts its own PDFs. It is
                 * therefore enforced where the condition is known, by
                 * App\Domain\Integration\Firma\SigningRequestCreation::requireTemplateScope(),
                 * and a request that supplies `template_id` without it is a 403 naming the
                 * scope exactly as this middleware would.
                 */
                Route::middleware('require-scope:envelopes:write')->group(function (): void {
                    Route::post('/', [SigningRequestController::class, 'store'])->name('store');

                    Route::post('/create-and-send', [SigningRequestController::class, 'createAndSend'])
                        ->name('create-and-send');

                    Route::patch('/{id}', [SigningRequestController::class, 'update'])->name('update');
                    Route::post('/{id}/send', [SigningRequestController::class, 'send'])->name('send');
                    Route::post('/{id}/cancel', [SigningRequestController::class, 'cancel'])->name('cancel');
                });

                Route::middleware('require-scope:envelopes:read')->group(function (): void {
                    Route::get('/{id}', [SigningRequestController::class, 'show'])->name('show');
                    Route::get('/{id}/users', [SigningRequestUserController::class, 'index'])->name('users');
                    Route::get('/{id}/fields', [SigningRequestFieldController::class, 'index'])->name('fields');
                    Route::get('/{id}/download', [SigningRequestDownloadController::class, 'show'])->name('download');
                });
            });

        /*
         * Everything else under this base path.
         *
         * 501 naming the path and the method, never 404 and never a plausible empty success.
         * See App\Http\Controllers\Compat\Firma\UnsupportedEndpointController for why each of
         * those alternatives is worse, and the "Out of profile" section of the capability
         * matrix for the list.
         *
         * `Route::any` rather than `Route::fallback`, because `fallback()` registers a `GET`
         * route only: `POST /signing-requests/{id}/resend` and `DELETE /signing-requests/{id}`
         * are both real upstream routes that are out of this profile, and both would have
         * answered `405 Method Not Allowed` — which reads as "you used the wrong verb on an
         * endpoint we have" rather than "we do not implement that endpoint". Declared last, so
         * every route above it is matched first.
         */
        Route::any('/{unsupported}', UnsupportedEndpointController::class)
            ->where('unsupported', '.*')
            ->name('unsupported');
    });
