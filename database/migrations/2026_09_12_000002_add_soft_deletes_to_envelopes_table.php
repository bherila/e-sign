<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deleted_at` on envelopes, so executed-evidence retention has something reversible to do.
 *
 * Retention of an executed agreement is a two-stage operation on purpose
 * (docs/operations/retention.md). The first stage soft-deletes the envelope and writes an
 * audit event naming every artifact digest that is scheduled to go; the second stage removes
 * the bytes, and only for rows that have been soft-deleted longer than the configured grace
 * period. Between the two there is a window in which the decision is still a single UPDATE
 * away from being undone, which is the difference between a retention policy and an
 * accident.
 *
 * The `artifacts` rows themselves are never deleted or edited — the model refuses both — so
 * the digests, the seal key id, and the validation report of a purged agreement survive as
 * the record that it existed and what it was. What is destroyed is the document, not the
 * evidence that there was one.
 *
 * A soft-deleted envelope is hidden from every ordinary query by the model's global scope,
 * which is why `esign:artifacts:verify` skips it: an object whose bytes retention removed on
 * purpose is not an integrity failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->softDeletes()->after('legal_hold_by');
        });

        Schema::table('envelopes', function (Blueprint $table): void {
            // The blob purge scans "soft-deleted before X" across the whole table.
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
