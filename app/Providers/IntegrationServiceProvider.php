<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Integration\Native\ApiErrorMap;
use App\Domain\Integration\Native\ArtifactLocator;
use App\Domain\Integration\Native\Console\PruneIdempotencyKeysCommand;
use App\Domain\Integration\Native\FinalizedArtifactLocator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wires the Integration module: the native API's one port, and its one command.
 *
 * {@see ArtifactLocator} is bound to {@see FinalizedArtifactLocator}, the `artifacts` table.
 * It was bound to `NoArtifactsYetLocator` — which finds nothing, and so made every artifact
 * route say plainly that there was nothing to download rather than hand a caller an empty
 * PDF (AGENTS.md, "Fail closed") — until finalization landed. That was the whole purpose of
 * the seam: this one line is the change, and nothing in app/Http moved with it.
 * `NoArtifactsYetLocator` is kept, because a deployment with finalization switched off still
 * needs an honest answer and a test still needs the empty side of the seam.
 *
 * The console command is registered here rather than in bootstrap/app.php because Laravel's
 * auto-discovery only scans app/Console/Commands and never looks inside a domain module —
 * the same reason DeliveryServiceProvider registers its own.
 */
final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArtifactLocator::class, FinalizedArtifactLocator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneIdempotencyKeysCommand::class,
            ]);
        }

        $this->silenceExpectedRefusals();
    }

    /**
     * Keep the native API's own refusals out of the error log.
     *
     * `Illuminate\Routing\Pipeline` reports every exception it renders, so without this a
     * `409 illegal_transition` — an integration calling send twice, which both surfaces
     * answer correctly and immediately — would be logged as an application error on every
     * retry. A log full of those hides the one exception that does matter, and an operator
     * paged for one learns nothing.
     *
     * The suppression is narrow in both directions. It applies only to requests under
     * `/api/v1` and under the facade's base path, so the signing UI, the console, and the
     * queue keep reporting the same exceptions exactly as before; and only to refusals the
     * surface's own map recognises, so anything genuinely unexpected is still reported in
     * full and still answers 500. Each surface is asked its own question, because the two
     * maps classify differently — a `SigningUrlUnavailable` is an unimplemented option on the
     * facade and an application fault on the native API.
     *
     * Registered here rather than in bootstrap/app.php because it is a fact about these two
     * surface, not about the application, and it belongs next to the module that decides
     * what counts as a refusal.
     */
    private function silenceExpectedRefusals(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! $handler instanceof Handler) {
            return;
        }

        $handler->reportable(function (Throwable $exception): ?bool {
            $request = $this->currentRequest();

            if (! $request instanceof Request) {
                return null;
            }

            // Returning false stops reporting; null lets the default reporting continue.
            return match (true) {
                $request->is('api/v1/*') => ApiErrorMap::isExpectedRefusal($exception) ? false : null,
                default => null,
            };
        });
    }

    private function currentRequest(): ?Request
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request instanceof Request ? $request : null;
    }
}
