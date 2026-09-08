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
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
