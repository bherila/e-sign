<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A public identifier for a membership, so the members page can address one without putting an
 * autoincrement id on an HTTP surface (issue #110).
 *
 * Every existing row is given one here. The column stays nullable at the schema level because
 * making it NOT NULL means rebuilding the table on SQLite, and the model assigns it on creation,
 * which is the only way rows are inserted. Nothing references a membership by this id, so the
 * no-cascade rule of the table is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->string('public_id', 26)->nullable()->after('id');
        });

        DB::table('workspace_memberships')
            ->whereNull('public_id')
            ->orderBy('id')
            ->select('id')
            ->lazyById()
            ->each(static function (object $row): void {
                DB::table('workspace_memberships')->where('id', $row->id)->update(['public_id' => (string) Str::ulid()]);
            });

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
