<?php

use App\Domain\Identity\Console\BootstrapOwnerCommand;
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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('functions/*')
                || $request->expectsJson(),
        );
    })->create();
