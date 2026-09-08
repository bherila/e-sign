<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts the Delivery module owns about a recipient: when we first wrote to them, and
 * when we last nudged them.
 *
 * They live on `envelope_recipients` rather than in a table of their own because both are
 * one nullable moment per party and both are read on the same row the scheduler is already
 * scanning. They are deliberately **not** signing state: nothing in
 * `App\Domain\Signing\Envelopes\EnvelopeStateMachine` reads or writes them, and a recipient's
 * eligibility never depends on whether their mail was sent.
 *
 * `invited_at` is what makes invitations exactly-once without a distributed lock. The
 * invitation job claims a recipient with `UPDATE … WHERE invited_at IS NULL` and sends only
 * if that affected one row, which is the same compare-and-swap discipline the state machine
 * uses for transitions and works identically on SQLite, MySQL 8, and MariaDB
 * (docs/adr/0002-supported-databases.md). It is also the clock a reminder is measured from:
 * "waiting for three days" means three days since they were asked, not since the row existed.
 *
 * `last_reminded_at` stops the daily scheduler from sending the same nudge every run. Null
 * means never reminded, which is a different fact from "reminded a long time ago" and is why
 * the column is nullable rather than defaulting to the invitation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_recipients', function (Blueprint $table): void {
            $table->timestamp('invited_at')->nullable()->after('declined_at');
            $table->timestamp('last_reminded_at')->nullable()->after('invited_at');

            // The reminder scan is "still active, invited a while ago"; the state column is
            // already indexed with envelope_id, and this covers the second half of it.
            $table->index(['state', 'invited_at']);
        });
    }

    public function down(): void
    {
        Schema::table('envelope_recipients', function (Blueprint $table): void {
            $table->dropIndex(['state', 'invited_at']);
            $table->dropColumn(['invited_at', 'last_reminded_at']);
        });
    }
};
