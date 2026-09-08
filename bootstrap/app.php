<?php

use App\Domain\Identity\Console\BootstrapOwnerCommand;
use App\Domain\Identity\Console\CreateUserCommand;
use App\Domain\Identity\Credentials\Console\IssueServiceCredentialCommand;
use App\Domain\Identity\Credentials\Console\ListServiceCredentialsCommand;
use App\Domain\Identity\Credentials\Console\RevokeServiceCredentialCommand;
use App\Domain\Identity\Credentials\Console\RotateServiceCredentialCommand;
use App\Domain\Integration\Native\ErrorCode;
use App\Http\Middleware\AuthenticateServiceCredential;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `/up` above is Laravel's minimal liveness probe. `routes/health.php` adds the
        // detailed `/health/ready` readiness probe (issue #16); it is registered here
        // rather than merged into web/api so it never picks up session or CSRF middleware.
        // `routes/documents.php` (issue #19) declares its own `web` + `auth` stack for the
        // same reason: the file that defines a route also defines what protects it.
        then: function (): void {
            Route::group([], base_path('routes/health.php'));
            Route::group([], base_path('routes/documents.php'));
            Route::group([], base_path('routes/templates.php'));
            Route::group([], base_path('routes/editor.php'));
            Route::group([], base_path('routes/mail-webhooks.php'));
            Route::group([], base_path('routes/signing.php'));
        },
    )
    // Domain commands live under app/Domain/<Module>/Console, which Laravel's
    // app/Console/Commands auto-discovery does not scan, so they are listed here.
    ->withCommands([
        BootstrapOwnerCommand::class,
        CreateUserCommand::class,
        IssueServiceCredentialCommand::class,
        RotateServiceCredentialCommand::class,
        RevokeServiceCredentialCommand::class,
        ListServiceCredentialsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Service credentials are their own kind of principal (docs/HANDOFF.md section 10),
        // so these are aliases applied per route and never global middleware: most of the
        // application is for people, and an API caller has no session, no membership, and no
        // role. Scope enforcement is a second alias on purpose, so a route states the scope
        // it needs next to itself.
        $middleware->alias([
            'service-credential' => AuthenticateServiceCredential::class,
            'require-scope' => RequireScope::class,
        ]);

        // Global, because "every response" is the requirement and a route group is a place
        // to forget. It writes a header only when one is not already set, so
        // SigningSecurityHeaders stays authoritative on the guest surface
        // (docs/security/review-2026-09.md findings X-1, X-2, X-3).
        $middleware->append(SecurityHeaders::class);

        // Empty by default, which is exactly today's behaviour: with no proxy trusted,
        // `$request->ip()` is REMOTE_ADDR and an `X-Forwarded-For` header cannot move a rate
        // limiter key, a health allowlist decision, or an evidence record. What was missing
        // was any supported way to say otherwise — `.env.example` and `config/bherila-auth.php`
        // both told an operator to configure trusted proxies and there was no mechanism
        // (docs/security/review-2026-09.md finding X-5). Behind a reverse proxy, set
        // TRUSTED_PROXIES to the proxy's addresses, or to `*` only when nothing but the proxy
        // can reach the application's port.
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('functions/*')
                // Provider callbacks. They are outside the `web` group and so have no
                // session, and Laravel renders a ValidationException as
                // `redirect()->back()->withInput()`, which touches the session store and
                // raises a 500 on a request that has none. A malformed body from a provider
                // must be a 422 (docs/security/review-2026-09.md finding X-10).
                || $request->is('webhooks/*')
                || $request->expectsJson(),
        );

        // A one-time code submitted to the landing page must not be flashed into the
        // `sessions` table when validation refuses it for being the wrong shape. The OTP
        // path deliberately re-renders rather than redirecting for exactly this reason;
        // this covers the shape refusal that happens before the controller runs
        // (docs/security/review-2026-09.md finding X-14).
        $exceptions->dontFlash(['code', 'current_password', 'password', 'password_confirmation']);

        // `Route::fallback()` registers for GET only, so an unmatched POST/PATCH/DELETE under
        // /api/v1 is refused by the *router* — before any route middleware, which means
        // ApiErrorBoundary never runs and never gets to shape the answer. The result was
        // Laravel's flat `{"message": …}`, naming the supported verbs, and with APP_DEBUG on a
        // full stack trace with absolute paths on an unauthenticated endpoint. Mapped here,
        // where a router-level refusal can still be caught
        // (docs/security/review-2026-09.md finding A-4).
        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => ErrorCode::MethodNotAllowed->value,
                    'message' => 'That method is not supported on this endpoint.',
                ],
            ], Response::HTTP_METHOD_NOT_ALLOWED);
        });
    })->create();
