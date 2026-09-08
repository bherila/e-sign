<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Example feature test demonstrating safe database usage.
 *
 * This test uses RefreshDatabase, which will run migrations on each test.
 * Because we enforce SQLite in-memory via SafeTestCase, this is safe
 * and will never accidentally affect a MySQL database.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the application returns a successful response.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * Test that SafeTestCase is enforcing the database it's supposed to.
     *
     * By default that's SQLite in-memory. The one exception is the CI-only `database`
     * workflow job, which opts SafeTestCase into a real engine via ESIGN_TEST_DB_ENGINE (see
     * Tests\Support\TestDatabaseGuard); when that opt-in is active, this test confirms we
     * landed on the disposable CI database it authorized, not just any non-SQLite connection.
     */
    public function test_database_matches_the_active_safe_test_case_policy(): void
    {
        $ciEngine = env('ESIGN_TEST_DB_ENGINE');

        if ($ciEngine === null) {
            $this->assertEquals('sqlite', $this->getDatabaseDriver());
            $this->assertEquals(':memory:', $this->getDatabaseName());

            return;
        }

        $this->assertEquals($ciEngine, $this->getDatabaseDriver());
        $this->assertStringStartsWith('esign_ci_test', $this->getDatabaseName());
    }

    /**
     * Test that database tables can be created via migrations.
     *
     * This confirms RefreshDatabase is working with SQLite.
     */
    public function test_migrations_create_expected_tables(): void
    {
        // These tables should exist after RefreshDatabase runs migrations
        $this->assertTrue(
            \Schema::hasTable('users'),
            'Users table should exist after migrations'
        );
        $this->assertTrue(
            \Schema::hasTable('sessions'),
            'Sessions table should exist after migrations'
        );
        $this->assertTrue(
            \Schema::hasTable('cache'),
            'Cache table should exist after migrations'
        );
        $this->assertTrue(
            \Schema::hasTable('jobs'),
            'Jobs table should exist after migrations'
        );
    }
}
