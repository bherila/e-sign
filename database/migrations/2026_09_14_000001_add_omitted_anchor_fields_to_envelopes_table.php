<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of fields left out because an optional anchor was not in the document.
 *
 * docs/HANDOFF.md section 7 allows one narrow compatibility option: an *optional* field whose
 * anchor declares `required: false` may find no matching text, and is then omitted rather than
 * placed at a default position. The specification says the option must be "narrow and
 * observable", and this column is the observable half.
 *
 * A column rather than only a log line or an audit event, because the question it answers is asked
 * about one envelope, long afterwards: *why is there no notes box on this agreement?* Here it sits
 * next to the envelope and comes back with it through the API. The audit event is written as well
 * — a fact and its history, not a duplicate.
 *
 * Nullable JSON, and null is the ordinary case: nothing was omitted. The state machine writes it
 * once, in the same statement as the send transition. Not a snapshot column: it is written at send,
 * not copied at creation, and records what the service decided rather than what the sender supplied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->json('omitted_anchor_fields')->nullable()->after('field_schema_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropColumn('omitted_anchor_fields');
        });
    }
};
