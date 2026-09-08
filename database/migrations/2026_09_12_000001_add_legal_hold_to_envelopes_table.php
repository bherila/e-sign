<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal hold on one envelope.
 *
 * A hold is the application's own deletion restriction and nothing more. Garage's documented
 * S3 implementation has neither Object Lock nor object versioning, so this is deliberately
 * not described as bucket-enforced legal hold or as WORM (docs/HANDOFF.md section 12): it is
 * a column three commands and every deletion path consult, backed by an audit event, and its
 * strength is exactly the strength of the code that reads it. Independent off-host copies are
 * what resist a determined deletion; see docs/operations/backups.md.
 *
 * Three columns rather than a boolean, because "held" on its own is not auditable:
 *
 *  - `legal_hold_at` is the fact, and its absence is the absence of a hold. A single
 *    nullable timestamp means a placement cannot be half-applied.
 *  - `legal_hold_reason` is required by the placing command and stored so the next operator
 *    does not have to find the ticket that prompted it.
 *  - `legal_hold_by` is a free-text actor label rather than a `users` foreign key: a hold is
 *    normally placed from the console during an investigation, where there is no
 *    authenticated user, and the trail must not imply one. The full actor shape is in the
 *    audit event; this column is what a listing can show without a join.
 *
 * Nullable throughout, so the migration is a pure addition on a deployed instance and no
 * existing envelope is silently held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->timestamp('legal_hold_at')->nullable()->after('finalization_failure_reason');
            $table->string('legal_hold_reason', 500)->nullable()->after('legal_hold_at');
            $table->string('legal_hold_by', 191)->nullable()->after('legal_hold_reason');
        });

        // Retention sweeps read "is this envelope held?" for every candidate, so the
        // predicate is worth an index of its own.
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->index('legal_hold_at');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropIndex(['legal_hold_at']);
            $table->dropColumn(['legal_hold_at', 'legal_hold_reason', 'legal_hold_by']);
        });
    }
};
