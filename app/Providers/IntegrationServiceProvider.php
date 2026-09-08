<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Integration\Native\ApiErrorMap;
use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\Console\PruneIdempotencyKeysCommand;
use App\Domain\Integration\Native\NoArtifactsYetLocator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wires the Integration module: the native API's one port, and its one command.
 *
 * {@see ArtifactLocator} is bound to {@see NoArtifactsYetLocator}, which finds nothing. That
 * is not a placeholder that pretends: finding nothing makes the artifact routes answer
 * plainly that there is nothing to download, and no caller is ever handed an empty or
 * partial PDF (AGENTS.md, "Fail closed"). When finalization lands it binds its own
 * implementation over this line and every artifact route starts working with no change in
 * app/Http.
 *
 * The console command is registered here rather than in bootstrap/app.php because Laravel's
 * auto-discovery only scans app/Console/Commands and never looks inside a domain module —
 * the same reason DeliveryServiceProvider registers its own.
 */
final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArtifactLocator::class, NoArtifactsYetLocator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneIdempotencyKeysCommand::class,
            ]);
        }

        $this->silenceExpectedApiRefusals();
    }

    /**
     * Keep the native API's own refusals out of the error log.
     *
     * `Illuminate\Routing\Pipeline` reports every exception it renders, so without this a
     * `409 illegal_transition` — an integration calling send twice, which the API answers
     * correctly and immediately — would be logged as an application error on every retry. A
     * log full of those hides the one exception that does matter, and an operator paged for
     * one learns nothing.
     *
     * The suppression is narrow in both directions. It applies only to requests under
     * `/api/v1`, so the signing UI, the console, and the queue keep reporting the same
     * exceptions exactly as before; and only to refusals {@see ApiErrorMap} recognises, so
     * anything genuinely unexpected is still reported in full and still answers 500.
     *
     * Registered here rather than in bootstrap/app.php because it is a fact about this
     * surface, not about the application, and it belongs next to the module that decides
     * what counts as a refusal.
     */
    private function silenceExpectedApiRefusals(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! $handler instanceof Handler) {
            return;
        }

        $handler->reportable(function (Throwable $exception): ?bool {
            if (! $this->isNativeApiRequest()) {
                return null;
            }

            // Returning false stops reporting; null lets the default reporting continue.
            return ApiErrorMap::isExpectedRefusal($exception) ? false : null;
        });
    }

    private function isNativeApiRequest(): bool
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return false;
        }

        $request = $this->app->make('request');

        return $request instanceof Request && $request->is('api/v1/*');
    }
}
