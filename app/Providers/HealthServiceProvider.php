<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\Health\Console\DoctorCommand;
use App\Domain\Delivery\Health\Probes\DatabaseProbe;
use App\Domain\Delivery\Health\Probes\MailBacklogProbe;
use App\Domain\Delivery\Health\Probes\MailProbe;
use App\Domain\Delivery\Health\Probes\QueueProbe;
use App\Domain\Delivery\Health\Probes\SchedulerHeartbeatProbe;
use App\Domain\Delivery\Health\Probes\SigningMaterialProbe;
use App\Domain\Delivery\Health\Probes\StorageProbe;
use App\Domain\Delivery\Health\Probes\TsaProbe;
use App\Domain\Delivery\Health\Probes\WebhookBacklogProbe;
use App\Domain\Delivery\Health\ReadinessChecker;
use Illuminate\Support\ServiceProvider;

class HealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReadinessChecker::class, function ($app): ReadinessChecker {
            return new ReadinessChecker([
                $app->make(DatabaseProbe::class),
                $app->make(QueueProbe::class),
                $app->make(SchedulerHeartbeatProbe::class),
                $app->make(StorageProbe::class),
                $app->make(MailProbe::class),
                $app->make(MailBacklogProbe::class),
                $app->make(WebhookBacklogProbe::class),
                $app->make(SigningMaterialProbe::class),
                $app->make(TsaProbe::class),
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
            ]);
        }
    }
}
