<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MariaDB 10.6 (explicit_defaults_for_timestamp=OFF) gives the first NOT NULL TIMESTAMP
 * column of a table an implicit DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP. Any
 * later UPDATE to the row then silently rewrites that column: an invitation's expiry moved
 * every time it was consumed or revoked, a finalization run's start time moved on every
 * state change. MySQL 8 and MariaDB 11.4 do not do this, so only the 10.6 CI job sees it.
 *
 * DATETIME has no such magic on any supported engine. These tables have already been
 * created on a deployed instance, so this is an ALTER rather than an edit of the original
 * migrations (AGENTS.md, Database safety rule 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dateTime('occurred_at')->change();
        });

        Schema::table('recipient_invitations', function (Blueprint $table): void {
            $table->dateTime('expires_at')->change();
        });

        Schema::table('recipient_attestations', function (Blueprint $table): void {
            $table->dateTime('accepted_at')->change();
        });

        Schema::table('finalization_runs', function (Blueprint $table): void {
            $table->dateTime('started_at')->change();
        });
    }

    public function down(): void
    {
        // Intentionally left as DATETIME: reverting would reintroduce the implicit ON UPDATE
        // behaviour on MariaDB 10.6.
    }
};
