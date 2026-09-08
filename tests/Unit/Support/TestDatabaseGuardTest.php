<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * Pure unit coverage for the guard behind SafeTestCase's non-SQLite opt-in. No database
 * connection of any kind is involved; this only exercises the allow/deny decision.
 */
class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('allowedCases')]
    public function test_allows_the_ci_opt_in(?string $engine, string $driver, string $database, ?string $host): void
    {
        $this->assertTrue(
            TestDatabaseGuard::isAllowedNonSqliteConnection($engine, $driver, $database, $host)
        );
    }

    public static function allowedCases(): array
    {
        return [
            'mysql on 127.0.0.1' => ['mysql', 'mysql', 'esign_ci_test', '127.0.0.1'],
            'mariadb on 127.0.0.1' => ['mariadb', 'mariadb', 'esign_ci_test', '127.0.0.1'],
            'mysql on localhost' => ['mysql', 'mysql', 'esign_ci_test', 'localhost'],
            'paratest-suffixed database, process 1' => ['mysql', 'mysql', 'esign_ci_test_test_1', '127.0.0.1'],
            'paratest-suffixed database, process 12' => ['mariadb', 'mariadb', 'esign_ci_test_test_12', 'localhost'],
        ];
    }

    #[DataProvider('deniedCases')]
    public function test_denies_everything_else(?string $engine, string $driver, string $database, ?string $host): void
    {
        $this->assertFalse(
            TestDatabaseGuard::isAllowedNonSqliteConnection($engine, $driver, $database, $host)
        );
    }

    public static function deniedCases(): array
    {
        return [
            'no opt-in set' => [null, 'mysql', 'esign_ci_test', '127.0.0.1'],
            'empty opt-in' => ['', 'mysql', 'esign_ci_test', '127.0.0.1'],
            'unsupported engine name' => ['postgres', 'pgsql', 'esign_ci_test', '127.0.0.1'],
            'opt-in engine does not match active driver' => ['mysql', 'mariadb', 'esign_ci_test', '127.0.0.1'],
            'wrong database name' => ['mysql', 'mysql', 'production', '127.0.0.1'],
            'database name only a prefix match' => ['mysql', 'mysql', 'esign_ci_test_other', '127.0.0.1'],
            'paratest suffix on the wrong base name' => ['mysql', 'mysql', 'production_test_1', '127.0.0.1'],
            'non-numeric paratest suffix' => ['mysql', 'mysql', 'esign_ci_test_test_abc', '127.0.0.1'],
            'remote host' => ['mysql', 'mysql', 'esign_ci_test', 'db.example.com'],
            'null host' => ['mysql', 'mysql', 'esign_ci_test', null],
            'sqlite driver requesting the opt-in' => ['mysql', 'sqlite', 'esign_ci_test', '127.0.0.1'],
        ];
    }
}
