<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\Health\HealthStatus;
use App\Domain\Delivery\Health\Probes\DatabaseProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_ok_when_connected_with_no_pending_migrations(): void
    {
        $result = $this->app->make(DatabaseProbe::class)->check();

        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('No pending migrations', $result->message);
    }

    public function test_warns_about_a_pending_migration_via_a_fake_migration_path(): void
    {
        $fakeMigrationsPath = sys_get_temp_dir().'/esign-health-test-migrations-'.uniqid();
        File::ensureDirectoryExists($fakeMigrationsPath);
        File::put(
            $fakeMigrationsPath.'/2099_01_01_000000_fake_pending_migration.php',
            "<?php\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {\n public function up(): void {}\n public function down(): void {}\n};\n",
        );

        $this->app->make('migrator')->path($fakeMigrationsPath);

        try {
            $result = $this->app->make(DatabaseProbe::class)->check();

            $this->assertSame(HealthStatus::Warn, $result->status);
            $this->assertStringContainsString('1 migration is pending', $result->message);
        } finally {
            File::deleteDirectory($fakeMigrationsPath);
        }
    }
}
