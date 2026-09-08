<?php

use App\Domain\Identity\Console\BootstrapOwnerCommand;
use App\Http\Middleware\AuthenticateServiceCredential;
use App\Http\Middleware\RequireScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Domain commands live under app/Domain/<Module>/Console, which Laravel's
    // app/Console/Commands auto-discovery does not scan, so they are listed here.
    ->withCommands([
        BootstrapOwnerCommand::class,
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('functions/*')
                || $request->expectsJson(),
        );
    })->create();
