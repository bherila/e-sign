<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable revision ledger for a document.
 *
 * Two kinds exist today. `original` is the upload exactly as received. `review` is the
 * revision a signer is shown and that final assembly builds on; it is either the same bytes
 * (recorded as `normalization: {"applied": false, ...}`) or the output of an explicit,
 * disclosed normalization step. Nothing else may be shown for assent, because invariant 2
 * in docs/ARCHITECTURE.md binds an acceptance to a specific review revision.
 *
 * There is a `created_at` and no `updated_at`: a revision row is written once.
 * App\Domain\Preparation\Documents\Models\DocumentRevision refuses updates and deletes, for
 * the same portability reason the audit table does it in the model rather than a trigger.
 *
 * The unique key on (document_id, kind, sha256) makes intake idempotent per content: the
 * storage key is derived from the same three values, so re-recording identical bytes cannot
 * produce a second row pointing at the same object. It deliberately does NOT prevent two
 * different digests for one kind — a future disclosed re-normalization is a new revision,
 * never an overwrite of an old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            // 'original' | 'review'. A string, not a native ENUM; see the documents table.
            $table->string('kind', 32);

            $table->string('disk', 64);
            $table->string('path', 512);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('page_count')->nullable();

            // What was changed relative to the original, in the sender's terms. Never null:
            // "nothing was changed" is a positive statement that has to be recorded, not the
            // absence of a record.
            $table->json('normalization');

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['document_id', 'kind', 'sha256']);
            $table->index(['document_id', 'kind']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
