<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fallback matches config/bherila-auth.php. It only applies when the config is
     * missing or stale — exactly the case a wrong fallback would silently break, which
     * is what `webauthn_credentials` did here.
     */
    private function table(): string
    {
        return (string) config('bherila-auth.passkeys.table', 'auth_passkeys');
    }

    /**
     * Records which relying-party ID each credential is bound to.
     *
     * Existing rows are deliberately left NULL rather than backfilled with a guess: the
     * value a credential was actually registered against is not recoverable after the
     * fact, and a wrong value reads as authoritative. NULL means "registered before this
     * was recorded", which is the truth.
     */
    public function up(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'rp_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->string('rp_id', 255)->nullable()->after('aaguid');
        });
    }

    public function down(): void
    {
        $table = $this->table();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'rp_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->dropColumn('rp_id');
        });
    }
};
