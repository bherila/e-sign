<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProviderEpochSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembered_logins_keep_legacy_baselines_nullable_and_can_capture_an_epoch(): void
    {
        $this->assertTrue(Schema::hasTable('provider_remembered_logins'));
        $this->assertTrue(Schema::hasColumn('provider_remembered_logins', 'epoch'));
        DB::table('provider_remembered_logins')->insert([
            'guard' => 'web', 'user_id' => 'synthetic-account', 'provider' => 'bherila',
            'subject' => 'synthetic-subject', 'generation' => 7,
            'token_hash' => hash('sha256', 'synthetic-remember-token'), 'recorded_at' => now(),
        ]);
        $legacy = DB::table('provider_remembered_logins')->where('user_id', 'synthetic-account')->first();
        $this->assertNotNull($legacy);
        $this->assertNull($legacy->epoch);

        DB::table('provider_remembered_logins')->where('user_id', 'synthetic-account')->update(['epoch' => 2]);
        $captured = DB::table('provider_remembered_logins')->where('user_id', 'synthetic-account')->first();
        $this->assertNotNull($captured);
        $this->assertSame(2, (int) $captured->epoch);
        $this->assertSame(7, (int) $captured->generation);
        $this->assertSame($legacy->token_hash, $captured->token_hash);
    }
}
