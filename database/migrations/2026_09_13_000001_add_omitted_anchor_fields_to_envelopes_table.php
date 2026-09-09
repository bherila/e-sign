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
 * placed at a default position. The specification's own words are that the option must be
 * "narrow and observable", and this column is the observable half.
 *
 * It is a column rather than only a log line, and rather than only an audit event, because the
 * question it answers is asked about a specific envelope, long afterwards, by someone looking at
 * that envelope: *why is there no witness signature on this agreement?* An answer that lives in
 * a log file is an answer nobody finds, and one that lives only in the audit stream costs a scan
 * to reach. Here it is next to the envelope, comes back with it through the API, and can be
 * queried for. The audit event is written as well — the two are a fact and its history, not a
 * duplicate.
 *
 * Nullable JSON, and null is the ordinary case: it means nothing was omitted, which is different
 * from an empty list only in that no anchor ever asked. The state machine writes it exactly once,
 * in the same statement as the send transition, so the omission and the invitation it appears in
 * commit together.
 *
 * Not a snapshot column: it is written at send, not copied at creation, and it records what the
 * service decided rather than what the sender supplied.
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
