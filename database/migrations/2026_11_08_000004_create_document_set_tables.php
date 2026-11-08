<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordered document sets for envelopes and template versions (issue #163,
 * docs/preparation/document-sets.md).
 *
 * Expand-only. A single-document envelope or template version keeps no rows here: its set is
 * its own `document_revision_id`, exactly as before, so nothing existing is migrated and the
 * previous release reads every row it wrote. A row is written only for a set of two or more.
 *
 * `first_page` is the document's first page counted across the whole set (1-based), which is
 * what a field's `page` means in a set. `page_count` and `sha256` are copied from the review
 * revision when the row is written, so the set's boundaries and digest are a fact of the
 * envelope rather than something re-derived from rows that could change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('document_revision_id')->constrained('document_revisions')->restrictOnDelete();
            $table->string('title', 255);
            $table->char('sha256', 64);
            $table->unsignedInteger('page_count');
            $table->unsignedInteger('first_page');
            $table->timestamps();

            $table->unique(['envelope_id', 'position'], 'envelope_documents_envelope_position_unique');
        });

        Schema::create('template_version_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_version_id')->constrained('template_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('document_revision_id')->constrained('document_revisions')->restrictOnDelete();
            $table->string('title', 255);
            $table->char('sha256', 64);
            $table->unsignedInteger('page_count');
            $table->unsignedInteger('first_page');
            $table->timestamps();

            $table->unique(['template_version_id', 'position'], 'template_version_documents_version_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_version_documents');
        Schema::dropIfExists('envelope_documents');
    }
};
