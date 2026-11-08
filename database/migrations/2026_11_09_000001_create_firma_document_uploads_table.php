<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A temporary upload issued by the Firma facade's `POST /documents` (issue #182).
 *
 * Upstream answers that route with a `document_id` and a pre-signed storage URL. This service
 * never pre-signs (docs/BLOB_STORAGE.md rule 1), so the row stands for the upload instead: its
 * `public_id` is the `document_id` the caller is given, and the URL the caller PUTs to is an
 * application route that streams the bytes through the same intake and preflight as every other
 * upload. The small-document route writes the row with `document_id` already set.
 *
 * `document_id` is set once the bytes have been ingested and is nullable on delete, so the
 * retention sweep can still reclaim an upload nobody used. `consumed_at` marks the one use an
 * upload has: upstream's "consumed once the document is added".
 *
 * `dateTime()` for the NOT NULL `expires_at`, never a bare `timestamp()` (AGENTS.md, Database
 * safety rule 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firma_document_uploads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('service_credential_id')->nullable()->constrained('service_credentials')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('content_type', 127);
            $table->unsignedBigInteger('declared_bytes')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->dateTime('expires_at');
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'expires_at'], 'firma_document_uploads_workspace_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firma_document_uploads');
    }
};
