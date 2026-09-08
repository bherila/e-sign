<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An uploaded PDF, and the record of what preflight made of it.
 *
 * The `original_*` columns describe the bytes exactly as they were received. They are
 * written once, at intake, and never updated: the non-negotiable in AGENTS.md is that an
 * upload is never re-rendered, re-sealed, or overwritten. The bytes themselves live on a
 * private disk under a content-addressed key (see
 * App\Domain\Preparation\Documents\DocumentStorageKey); no path is ever returned to a
 * client, and downloads stream through the application.
 *
 * `workspace_id` is RESTRICT, per the no-cascade rule in the memberships migration: an
 * uploaded agreement outlives the membership that created it and the workspace that held
 * it. Documents are soft-deleted, which is also why the blob pruner in docs/BLOB_STORAGE.md
 * must read this table through the query builder rather than Eloquent.
 *
 * `status` is a plain string rather than a native ENUM so the column reads identically on
 * SQLite, MySQL 8, and MariaDB (docs/adr/0002-supported-databases.md). The application enum
 * App\Domain\Preparation\Documents\DocumentStatus is the authority.
 *
 * A document that fails preflight is kept, with status `preflight_failed`, its report, and
 * its original bytes. Discarding it would destroy the only evidence of what was uploaded;
 * see docs/preparation/documents.md for the reasoning and the retention consequences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            // Public identifier used in URLs and API payloads so the autoincrement id is
            // never enumerable from outside.
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('title');
            // Who uploaded it. RESTRICT for the same reason memberships are: deleting a user
            // row is a reviewed retention decision, never a side effect of revoking access.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();

            // The original, byte for byte. `original_disk` is a disk *name*, never a driver:
            // code must not branch on whether the bytes are on local or S3.
            $table->string('original_disk', 64);
            $table->string('original_path', 512);
            $table->char('original_sha256', 64);
            $table->unsignedBigInteger('original_bytes');
            $table->string('original_mime', 191);

            // Null until preflight has parsed the document; still null when it could not.
            $table->unsignedInteger('page_count')->nullable();
            $table->json('preflight_report')->nullable();
            $table->string('status', 32);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
            $table->index('original_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
