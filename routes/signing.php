<?php

declare(strict_types=1);

use App\Domain\Signing\Sessions\SigningToken;
use App\Http\Controllers\Signing\LegacyRecipientResolverController;
use App\Http\Controllers\Signing\SigningAssentController;
use App\Http\Controllers\Signing\SigningDocumentController;
use App\Http\Controllers\Signing\SigningLandingController;
use App\Http\Controllers\Signing\SigningSessionController;
use App\Http\Controllers\Signing\SigningValuesController;
use App\Http\Middleware\RequireSigningSession;
use App\Http\Middleware\SigningSecurityHeaders;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest signing (Stage 3, issues #25 and #26)
|--------------------------------------------------------------------------
|
| Its own file, registered from bootstrap/app.php's `then:` closure, for the
| reason routes/health.php, routes/documents.php, routes/templates.php and
| routes/editor.php are: the file that defines a route also defines what
| protects it, in one place.
|
| The `signing` stack is spelled out here rather than registered as a named
| middleware group in bootstrap/app.php, and deliberately. A named group is
| resolved somewhere else; a reader of this file would have to go and find out
| what `signing` means before they could tell whether a route is protected.
| Written out, the answer is on the line.
|
|   web                      session (for CSRF) and cookie handling. Guests get
|                            an esign_session cookie for the token and nothing
|                            else; their authorization is esign_signing, which is
|                            a separate cookie with a separate lifetime and a
|                            separate scope (App\Domain\Signing\Sessions\SigningCookie).
|   SigningSecurityHeaders   Referrer-Policy: no-referrer, X-Frame-Options: DENY,
|                            and a CSP with no third-party origin — on every
|                            response in this file, the streamed PDF included.
|   RequireSigningSession    A live session, scoped to the envelope in the URL.
|
| ## GET and POST are divided on purpose
|
| The four GETs here read rows and render. None of them consumes a token,
| creates a session, sends mail, or advances a recipient, because a recipient's
| mail provider fetches every URL in a message before the recipient does
| (AGENTS.md, "GET is harmless"). Everything that decides something is a POST
| with CSRF protection, and tests/Feature/Signing/HarmlessGetTest.php fetches
| every GET twice and asserts the agreement is unchanged.
|
| ## Parameter patterns
|
| Envelope and recipient identifiers are ULIDs, so a malformed one is a 404 at
| the router rather than a database query, and the autoincrement ids never
| appear on this surface. The token pattern is the exact shape
| SigningToken::generate() produces: 43 base64url characters. Constraining it
| means a scanner hammering /sign/{ulid}/{garbage} costs one regular expression
| and no query at all.
|
*/

Route::middleware(['web', SigningSecurityHeaders::class])
    ->whereUlid(['envelope', 'recipient'])
    ->where(['token' => '[A-Za-z0-9_-]{'.SigningToken::ENCODED_LENGTH.'}'])
    ->name('signing.')
    ->group(function (): void {

        /*
        | The invitation link. Safe for a scanner: it renders the agreement's
        | title, who sent it, and who is being asked to sign, and does nothing.
        */
        Route::get('/sign/{envelope}/{token}', [SigningLandingController::class, 'show'])
            ->name('landing');

        /*
        | The Continue button. The only route that spends an invitation. When the
        | envelope, its workspace, or the deployment asks for a mailed code, this
        | endpoint serves both halves of that exchange — see the controller.
        */
        Route::post('/sign/{envelope}/{token}/start', [SigningLandingController::class, 'start'])
            ->name('start');

        /*
        | Everything past here needs the esign_signing cookie and a session whose
        | envelope is the one in the path. There is no recipient in these URLs:
        | the session decides who is acting, so a path cannot be edited into
        | acting as somebody else.
        */
        Route::middleware(RequireSigningSession::class)->group(function (): void {

            // One page for the whole session. It renders the signing island, the
            // confirmation, or the decline notice depending on where the
            // recipient stands, so a reload after signing is never a 403.
            Route::get('/sign/{envelope}/session', [SigningSessionController::class, 'show'])
                ->name('session.show');

            // The review revision's bytes, streamed under the signing session
            // rather than through the administrative download routes. Inline
            // only; there is no attachment variant.
            Route::get('/sign/{envelope}/session/document', [SigningDocumentController::class, 'view'])
                ->name('session.document');

            // Saving field values. Signs nothing.
            Route::post('/sign/{envelope}/session/values', [SigningValuesController::class, 'store'])
                ->name('session.values');

            // The two decisions.
            Route::post('/sign/{envelope}/session/accept', [SigningAssentController::class, 'accept'])
                ->name('session.accept');

            Route::post('/sign/{envelope}/session/decline', [SigningAssentController::class, 'decline'])
                ->name('session.decline');
        });

        /*
        | The compatibility resolver. A bare recipient identifier reaches a form
        | and nothing else; mailbox verification is what turns it into a session.
        | docs/HANDOFF.md section 8 permits exactly this and no shortcut past it.
        */
        Route::get('/signing/{recipient}', [LegacyRecipientResolverController::class, 'show'])
            ->name('legacy.show');

        Route::post('/signing/{recipient}', [LegacyRecipientResolverController::class, 'request'])
            ->name('legacy.request');

        Route::post('/signing/{recipient}/verify', [LegacyRecipientResolverController::class, 'verify'])
            ->name('legacy.verify');
    });
