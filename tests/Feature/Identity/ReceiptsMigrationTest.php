<?php

namespace Tests\Feature\Identity;

use BWH\Auth\OAuth\DelegatedAccess\DatabaseReceiptStore;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The receipts table is created on the connection the receipt store reads, not the default one.
 */
class ReceiptsMigrationTest extends TestCase
{
    public function test_the_receipts_table_is_created_on_the_configured_connection(): void
    {
        config([
            'database.connections.receipts_elsewhere' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'bherila-auth.delegated_access.receipt_connection' => 'receipts_elsewhere',
        ]);
        $this->assertFalse(Schema::connection('receipts_elsewhere')->hasTable(DatabaseReceiptStore::TABLE));

        $migration = require database_path('migrations/2026_10_10_000000_create_delegated_access_receipts.php');
        $migration->up();

        $this->assertTrue(Schema::connection('receipts_elsewhere')->hasTable(DatabaseReceiptStore::TABLE));
        $this->assertFalse(Schema::hasTable(DatabaseReceiptStore::TABLE));
    }
}
