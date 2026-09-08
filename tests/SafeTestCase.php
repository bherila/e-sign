<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * SafeTestCase - A base test class that enforces SQLite usage.
 *
 * This class provides safety guarantees that tests will NEVER accidentally
 * connect to a production MySQL database, even if .env contains MySQL credentials.
 *
 * The phpunit.xml file sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:,
 * but this class adds runtime verification as an additional safety layer.
 *
 * The ONLY exception is the CI-only `database` workflow job, which runs migrations and the
 * feature suite against real MySQL/MariaDB service containers to catch engine-specific
 * behavior (see docs/adr/0002-supported-databases.md). That job sets ESIGN_TEST_DB_ENGINE to
 * `mysql` or `mariadb`, and the opt-in is honored only when it also targets the disposable
 * `esign_ci_test` database on 127.0.0.1/localhost — see Tests\Support\TestDatabaseGuard. A
 * developer's .env pointing at a real MySQL/MariaDB host is never enough on its own.
 *
 * Usage:
 *   - Feature tests should extend Tests\TestCase (which extends this class)
 *   - Use the RefreshDatabase trait freely - it will only affect SQLite in-memory (or, in the
 *     CI-only opt-in above, a disposable per-process CI database)
 */
abstract class SafeTestCase extends BaseTestCase
{
    /**
     * Boot the testing helper traits and verify database safety.
     */
    protected function setUpTraits(): array
    {
        $this->assertDatabaseIsSafeSqlite();

        return parent::setUpTraits();
    }

    /**
     * Assert that the database connection is SQLite in-memory.
     *
     * This is a critical safety check that prevents tests from accidentally
     * running against a MySQL database (which could contain production data).
     *
     * @throws RuntimeException if not using SQLite in-memory database
     */
    protected function assertDatabaseIsSafeSqlite(): void
    {
        $connection = DB::connection();
        $driverName = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if ($driverName !== 'sqlite') {
            if (TestDatabaseGuard::isAllowedNonSqliteConnection(
                env('ESIGN_TEST_DB_ENGINE'),
                $driverName,
                $database,
                $connection->getConfig('host'),
            )) {
                return;
            }

            throw new RuntimeException(
                "SAFETY ERROR: Tests must use SQLite, but '{$driverName}' connection is active. ".
                "This could lead to accidentally modifying a production database!\n\n".
                "Ensure phpunit.xml contains:\n".
                "  <env name=\"DB_CONNECTION\" value=\"sqlite\"/>\n".
                "  <env name=\"DB_DATABASE\" value=\":memory:\"/>\n\n".
                "And run tests with: composer test (or php artisan test)\n\n".
                'The only exception is the CI `database` job, which opts in via '.
                'ESIGN_TEST_DB_ENGINE=mysql|mariadb against the disposable esign_ci_test '.
                'database on 127.0.0.1/localhost. See Tests\Support\TestDatabaseGuard.'
            );
        }

        if ($database !== ':memory:') {
            throw new RuntimeException(
                "SAFETY ERROR: Tests must use in-memory SQLite, but database is '{$database}'.\n\n".
                "Using a file-based database could persist test data unexpectedly.\n".
                "Ensure phpunit.xml contains:\n".
                '  <env name="DB_DATABASE" value=":memory:"/>'
            );
        }
    }

    /**
     * Get the current database driver name for assertions in tests.
     */
    protected function getDatabaseDriver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Get the current database name for assertions in tests.
     */
    protected function getDatabaseName(): string
    {
        return DB::connection()->getDatabaseName();
    }
}
