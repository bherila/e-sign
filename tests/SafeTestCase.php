<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SafeTestCase - A base test class that enforces SQLite usage.
 *
 * This class provides safety guarantees that tests will NEVER accidentally
 * connect to a production MySQL database, even if .env contains MySQL credentials.
 *
 * The phpunit.xml file sets DB_CONNECTION=sqlite and DB_DATABASE=:memory:,
 * but this class adds runtime verification as an additional safety layer.
 *
 * Usage:
 *   - Feature tests should extend Tests\TestCase (which extends this class)
 *   - Use the RefreshDatabase trait freely - it will only affect SQLite in-memory
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
            throw new RuntimeException(
                "SAFETY ERROR: Tests must use SQLite, but '{$driverName}' connection is active. ".
                "This could lead to accidentally modifying a production database!\n\n".
                "Ensure phpunit.xml contains:\n".
                "  <env name=\"DB_CONNECTION\" value=\"sqlite\"/>\n".
                "  <env name=\"DB_DATABASE\" value=\":memory:\"/>\n\n".
                'And run tests with: composer test (or php artisan test)'
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
