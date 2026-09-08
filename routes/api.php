<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\EnvelopeArtifactController;
use App\Http\Controllers\Api\V1\EnvelopeController;
use App\Http\Controllers\Api\V1\EnvelopeEventController;
use App\Http\Controllers\Api\V1\EnvelopeValueController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\UnknownEndpointController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Middleware\Api\ApiErrorBoundary;
use App\Http\Middleware\Api\ApiIdempotency;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Two HTTP surfaces are mounted here, both backed by the same domain services
| (docs/ARCHITECTURE.md):
|
|   /api/v1                            native API (issue #32, below)
|   /functions/v1/signing-request-api  Firma-compatible facade (profile firma-compat-v1)
|
| The facade does not exist yet. Unsupported routes must fail clearly, never
| succeed as a no-op.
|
|--------------------------------------------------------------------------
| The native API
|--------------------------------------------------------------------------
|
| Read docs/api/native-v1.md for the contract this file implements. Four things
| about it are decided here, in the route definitions, rather than anywhere else:
|
| 1. **Who the caller is.** `service-credential` authenticates a service
|    principal from its API key, in either the raw or the Bearer syntax
|    (docs/operations/service-credentials.md). There is no session, no user, and
|    no membership on this surface.
|
| 2. **What it may do.** `require-scope` names the scope beside the route that
|    needs it, so an endpoint's authority is readable here instead of inside a
|    controller. Nothing is implied by anything else: `envelopes:write` does not
|    grant `envelopes:read`, and a route that reads and writes names both.
|
| 3. **Which tenant it sees.** Nowhere in this file is there a workspace
|    parameter, and no request body carries one. The credential's workspace is
|    the tenant for every lookup, and every lookup is constrained by it *before*
|    the identifier from the URL is used (docs/HANDOFF.md section 10). That is
|    why another tenant's id is a 404 here and never a 403.
|
| 4. **What a failure looks like.** `ApiErrorBoundary` is the outermost
|    middleware, so every error — including the 401 and 403 raised by the shared
|    credential middleware — leaves as `{"error": {"code", "message", "details"}}`
|    with a stable machine code. It is a middleware rather than a change to
|    bootstrap/app.php's handler because the facade owes different error bodies
|    on its own routes and must not inherit these.
|
| `ApiIdempotency` sits just inside authentication, because an `Idempotency-Key`
| is scoped to the credential that presented it. It is honoured on every mutating
| method and ignored on GET, which is already idempotent.
|
| Identifiers in URLs are public ULIDs. Constraining them keeps a malformed id a
| 404 at the router instead of a database query, and keeps autoincrement ids off
| this surface entirely.
|
*/

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware([ApiErrorBoundary::class])
    ->group(function (): void {

        /*
         * The contract itself, unauthenticated.
         *
         * A client needs to read the document before it has a credential, and there is
         * nothing in it that is not already public in this repository. Serving it behind
         * authentication would mean an integrator cannot generate a client until after an
         * operator has issued them a key.
         */
        Route::get('/openapi.json', OpenApiController::class)->name('openapi');

        Route::middleware(['service-credential', ApiIdempotency::class])->group(function (): void {

            // No scope. Every credential may ask what it is; requiring a resource scope to
            // answer "which workspace is this" would leave a webhooks-only credential unable
            // to verify its own configuration.
            Route::get('/me', [MeController::class, 'show'])->name('me');

            /*
             * Templates: read-only. Authoring means placing fields on a rendered PDF, which
             * is the workspace editor's job; `templates:write` is reserved for when that
             * lands rather than being granted by a route that does not exist.
             */
            Route::middleware('require-scope:templates:read')
                ->whereUlid('template')
                ->group(function (): void {
                    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
                    Route::get('/templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
                });

            /*
             * Envelopes, split by scope rather than by resource: reading an agreement and
             * sending one are different authorities, and an integration that only polls
             * should not be able to cancel.
             */
            Route::prefix('envelopes')->name('envelopes.')->whereUlid('envelope')->group(function (): void {

                Route::middleware('require-scope:envelopes:read')->group(function (): void {
                    Route::get('/{envelope}', [EnvelopeController::class, 'show'])->name('show');
                    Route::get('/{envelope}/recipients', [EnvelopeController::class, 'recipients'])->name('recipients');
                    Route::get('/{envelope}/values', [EnvelopeValueController::class, 'index'])->name('values');
                    Route::get('/{envelope}/events', [EnvelopeEventController::class, 'index'])->name('events');

                    Route::get('/{envelope}/artifacts', [EnvelopeArtifactController::class, 'index'])
                        ->name('artifacts.index');

                    // The whole evidence export as one archive, under `envelopes:read` like
                    // any other read of the agreement: it contains nothing a caller holding
                    // that scope cannot already fetch file by file, only assembled with the
                    // manifest that says what each digest covers (docs/HANDOFF.md section 8).
                    Route::get('/{envelope}/evidence-bundle', [EnvelopeArtifactController::class, 'bundle'])
                        ->name('evidence-bundle');

                    // The artifact id's shape belongs to whatever finalization records, so it
                    // is constrained to a safe character class rather than to a ULID this
                    // module does not own.
                    Route::get('/{envelope}/artifacts/{artifact}/download', [EnvelopeArtifactController::class, 'download'])
                        ->where('artifact', '[A-Za-z0-9._-]{1,128}')
                        ->name('artifacts.download');
                });

                Route::middleware('require-scope:envelopes:write')->group(function (): void {
                    Route::post('/', [EnvelopeController::class, 'store'])->name('store');
                    Route::patch('/{envelope}', [EnvelopeController::class, 'update'])->name('update');
                    Route::post('/{envelope}/send', [EnvelopeController::class, 'send'])->name('send');
                    Route::post('/{envelope}/cancel', [EnvelopeController::class, 'cancel'])->name('cancel');
                });
            });

            /*
             * Webhook administration. A scope of its own, not implied by any envelope scope:
             * an integration that reads agreements has no business redirecting a workspace's
             * event stream.
             */
            Route::middleware('require-scope:webhooks:manage')
                ->prefix('webhooks/endpoints')
                ->name('webhooks.endpoints.')
                ->whereUlid('endpoint')
                ->group(function (): void {
                    Route::get('/', [WebhookEndpointController::class, 'index'])->name('index');
                    Route::post('/', [WebhookEndpointController::class, 'store'])->name('store');
                    Route::patch('/{endpoint}', [WebhookEndpointController::class, 'update'])->name('update');
                    Route::delete('/{endpoint}', [WebhookEndpointController::class, 'destroy'])->name('destroy');
                    Route::post('/{endpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])
                        ->name('rotate-secret');
                });
        });

        // Anything else under /api/v1, in this API's error shape rather than the global
        // handler's. See UnknownEndpointController for why it is not a closure.
        Route::fallback(UnknownEndpointController::class);
    });
