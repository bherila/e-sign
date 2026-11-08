<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-document sealed copy is an artifact of its own (issue #163), so an envelope can hold
 * several of one kind. `slot` says which: 0 for every envelope-level artifact, which is every
 * artifact that existed before, and the document's `position` for an `executed_document`.
 *
 * Expand-only and order-sensitive. The new unique index is created **before** the old one is
 * dropped: on MySQL and MariaDB the old `(envelope_id, kind)` index may be the one backing the
 * foreign key on `envelope_id`, and an index a foreign key needs cannot be dropped until another
 * one leading with the same column exists. For every row the previous release writes, `slot` is
 * 0 by default, so the new index is exactly as strict as the old one was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('slot')->default(0)->after('kind');
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->unique(['envelope_id', 'kind', 'slot'], 'artifacts_envelope_id_kind_slot_unique');
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique('artifacts_envelope_id_kind_unique');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->unique(['envelope_id', 'kind'], 'artifacts_envelope_id_kind_unique');
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique('artifacts_envelope_id_kind_slot_unique');
            $table->dropColumn('slot');
        });
    }
};
