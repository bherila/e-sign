<?php

namespace Tests\Support;

/**
 * Pure guard logic behind SafeTestCase's non-SQLite opt-in.
 *
 * SafeTestCase refuses any driver but SQLite in-memory by default. The only exception is the
 * CI-only `database` workflow job (mysql/mariadb service containers), which sets
 * ESIGN_TEST_DB_ENGINE=mysql|mariadb to run migrations and the feature suite against the real
 * engine. That opt-in is honored only when it also targets the disposable CI database on
 * localhost, never an arbitrary host or database name a developer's .env might contain.
 *
 * Kept dependency-free (no DB facade, no Laravel container) so the guard logic is testable as
 * plain PHP without a live database connection of any kind.
 */
final class TestDatabaseGuard
{
    private const ALLOWED_ENGINES = ['mysql', 'mariadb'];

    private const ALLOWED_DATABASE = 'esign_ci_test';

    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost'];

    /**
     * Determine whether a non-SQLite connection is an explicitly opted-in CI database run.
     *
     * @param  string|null  $optInEngine  Value of the ESIGN_TEST_DB_ENGINE environment variable.
     * @param  string  $driverName  The active connection's driver name (e.g. 'mysql').
     * @param  string  $databaseName  The active connection's database name.
     * @param  string|null  $host  The active connection's host.
     */
    public static function isAllowedNonSqliteConnection(
        ?string $optInEngine,
        string $driverName,
        string $databaseName,
        ?string $host,
    ): bool {
        if ($optInEngine === null || $optInEngine === '') {
            return false;
        }

        if (! in_array($optInEngine, self::ALLOWED_ENGINES, true)) {
            return false;
        }

        // The opt-in must name the engine actually in use; ESIGN_TEST_DB_ENGINE=mysql never
        // authorizes a mariadb connection or vice versa.
        if ($optInEngine !== $driverName) {
            return false;
        }

        if (! self::isAllowedDatabaseName($databaseName)) {
            return false;
        }

        return self::isAllowedHost($host);
    }

    private static function isAllowedDatabaseName(string $databaseName): bool
    {
        if ($databaseName === self::ALLOWED_DATABASE) {
            return true;
        }

        // Laravel's ParallelTesting suffixes the configured database with _test_{token} per
        // paratest process (e.g. esign_ci_test_test_1). Allow that pattern only for the exact
        // CI database name, nothing else.
        $pattern = '/^'.preg_quote(self::ALLOWED_DATABASE, '/').'_test_\d+$/';

        return (bool) preg_match($pattern, $databaseName);
    }

    private static function isAllowedHost(?string $host): bool
    {
        return $host !== null && in_array($host, self::ALLOWED_HOSTS, true);
    }
}
