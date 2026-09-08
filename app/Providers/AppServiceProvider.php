<?php

namespace App\Providers;

use App\Listeners\UpdateLastLoginDate;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Csp\AddCspHeaders;

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

        // Register the Spatie CSP middleware globally if the HTTP kernel is available.
        if ($this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)
                ->pushMiddleware(AddCspHeaders::class);
        }
    }
}
