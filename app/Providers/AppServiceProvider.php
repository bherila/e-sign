<?php

namespace App\Providers;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Policies\WorkspacePolicy;
use App\Listeners\UpdateLastLoginDate;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Spatie\Csp\AddCspHeaders;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, UpdateLastLoginDate::class);

        // Registered explicitly: policy auto-discovery looks for App\Policies\<Model>Policy
        // and never finds a policy that lives in a domain module.
        Gate::policy(Workspace::class, WorkspacePolicy::class);

        // Register the Spatie CSP middleware globally if the HTTP kernel is available.
        if ($this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)
                ->pushMiddleware(AddCspHeaders::class);
        }

        // Brevo API transport (Symfony bridge) so MAIL_MAILER=brevo or =hybrid works. The DSN
        // lives in services.brevo.dsn; an unset DSN fails loudly at send time rather than
        // silently degrading to another mailer.
        $this->app['mail.manager']->extend('brevo', function (array $config): TransportInterface {
            $dsn = (string) $this->app->make('config')->get('services.brevo.dsn');

            if ($dsn === '') {
                throw new RuntimeException('MAILER_DSN is not set; the brevo mailer cannot be built.');
            }

            return (new BrevoTransportFactory)->create(Dsn::fromString($dsn));
        });
    }
}
