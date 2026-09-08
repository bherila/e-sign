<?php

namespace Tests;

/**
 * Base TestCase for all feature tests.
 *
 * Extends SafeTestCase which enforces SQLite in-memory database usage,
 * ensuring tests never accidentally connect to MySQL (even if .env has credentials).
 *
 * Feature tests should extend this class and use RefreshDatabase or
 * DatabaseTransactions as needed. Vite is disabled so views render without a
 * built asset manifest.
 */
abstract class TestCase extends SafeTestCase
{
    /**
     * Environment variables to apply before the application is created.
     *
     * Some things are decided once, at boot, and cannot be changed with `config()`
     * afterwards — most importantly which authentication mode's routes are registered
     * (routes/web.php). A test that needs a different mode declares it here, because by the
     * time `setUp()` has run the routes already exist.
     *
     * @var array<string, string>
     */
    protected array $envOverrides = [];

    protected function setUp(): void
    {
        foreach ($this->envOverrides as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // Unset before the next test in this process builds its application; a leaked
        // override would silently reconfigure an unrelated test.
        foreach (array_keys($this->envOverrides) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        parent::tearDown();
    }
}
