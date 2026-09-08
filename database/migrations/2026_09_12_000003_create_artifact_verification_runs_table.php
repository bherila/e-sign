<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of `esign:artifacts:verify`: when it ran, what it covered, and what it found.
 *
 * The table exists so a readiness probe can answer "when was the published evidence last
 * re-read, and did it still match?" without re-reading it. That question cannot be answered
 * from a cache key: the answer has to survive a cache flush, a deploy, and a worker restart,
 * because the failure it exists to surface — a scheduled verification that quietly stopped
 * running — looks exactly like a cache that was cleared.
 *
 * A row is written whether the run passed or failed, and a failing run is never overwritten
 * by a later passing one: the history is what makes "it has been failing since Tuesday"
 * visible. Findings are stored as a bounded list of digest comparisons and never as artifact
 * bytes; the count columns are what the probe reads.
 *
 * `started_at` is a `dateTime` rather than a `timestamp`, per the database-safety rule in
 * AGENTS.md: MariaDB 10.6 gives the first NOT NULL `TIMESTAMP` in a table an implicit
 * `ON UPDATE CURRENT_TIMESTAMP`, which would silently rewrite the start time of a run every
 * time its outcome was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifact_verification_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            // What the run covered. Null workspace means every workspace; null `since`
            // means every published artifact regardless of age. Both are recorded because a
            // narrow run is not evidence about the artifacts it did not look at.
            $table->ulid('workspace_public_id')->nullable();
            $table->timestamp('published_since')->nullable();

            $table->dateTime('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('artifacts_checked')->default(0);
            $table->unsignedInteger('digest_mismatches')->default(0);
            $table->unsignedInteger('missing_objects')->default(0);
            $table->unsignedInteger('invalid_signatures')->default(0);

            // Null while the run is in flight. False the moment anything did not match.
            $table->boolean('passed')->nullable();

            // A bounded list of {artifact, kind, problem} entries. Never artifact bytes and
            // never a storage key: a key contains a digest and a workspace identifier, and
            // this table is read by an operational probe.
            $table->json('findings')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['finished_at', 'passed']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_verification_runs');
    }
};
