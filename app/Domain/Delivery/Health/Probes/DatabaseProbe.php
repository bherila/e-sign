<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Health\Probes;

use App\Domain\Delivery\Health\HealthProbe;
use App\Domain\Delivery\Health\ProbeResult;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Confirms the default database connection accepts a trivial query and
 * reports how many migrations have not yet been run. A connection failure
 * never surfaces the driver, host, database name, or credentials.
 */
final class DatabaseProbe implements HealthProbe
{
    public function __construct(private readonly Migrator $migrator) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): ProbeResult
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable) {
            return ProbeResult::fail($this->name(), 'Database connection failed.');
        }

        $pending = $this->pendingMigrationCount();

        if ($pending === null) {
            return ProbeResult::ok($this->name(), 'Connected. Migration status unavailable.');
        }

        if ($pending > 0) {
            return ProbeResult::warn(
                $this->name(),
                $pending === 1 ? 'Connected. 1 migration is pending.' : "Connected. {$pending} migrations are pending.",
            );
        }

        return ProbeResult::ok($this->name(), 'Connected. No pending migrations.');
    }

    private function pendingMigrationCount(): ?int
    {
        try {
            if (! $this->migrator->repositoryExists()) {
                return null;
            }

            $paths = array_unique(array_merge($this->migrator->paths(), [database_path('migrations')]));
            $files = $this->migrator->getMigrationFiles($paths);
            $ran = $this->migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }
}
