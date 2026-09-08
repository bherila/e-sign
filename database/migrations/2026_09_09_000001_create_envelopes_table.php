<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One agreement in flight: the immutable copy of what is being signed, plus the single
 * lifecycle position the state machine owns.
 *
 * Everything an envelope needs is *copied* here at creation, never referenced. The field
 * schema, the document digest, the consent policy version, and the rendering settings are
 * snapshots, so a later edit to the template or the document cannot change what a signer
 * already reviewed (docs/HANDOFF.md section 6; docs/ARCHITECTURE.md invariants 2 and 4).
 * `source_template_version_id` records provenance only and deliberately carries no foreign
 * key: an envelope must survive the deletion of the version it came from, and it is a
 * public ULID rather than a row id because that is the identifier
 * App\Domain\Preparation\Templates\Models\TemplateVersion::snapshotForEnvelope() publishes.
 *
 * `state` and `signing_mode` and `assurance_level` are plain strings rather than native
 * ENUMs so the column reads identically on SQLite, MySQL 8, and MariaDB
 * (docs/adr/0002-supported-databases.md). The PHP enums are the authority.
 *
 * `version` is the compare-and-swap token. Every transition reads the row inside a
 * transaction with `lockForUpdate()` and then writes with `where('version', $expected)`,
 * asserting exactly one affected row. The lock is a no-op on SQLite, which is precisely why
 * the version predicate — and not the lock — is what decides a race
 * (docs/signing/state-machine.md).
 *
 * No cascades anywhere. An executed agreement outlives the workspace membership that
 * created it and the document row it points at; deleting either is a reviewed retention
 * decision, never a side effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('title');

            // Provenance only, and the *public* id of the template version rather than
            // its row id: that is what TemplateVersion::snapshotForEnvelope() exposes, and
            // it is the identifier that appears in API payloads. No FK: see the class
            // docblock.
            $table->ulid('source_template_version_id')->nullable();

            // What is being signed, and proof of which bytes those are.
            $table->foreignId('document_revision_id')->constrained('document_revisions')->restrictOnDelete();
            $table->char('document_sha256', 64);

            // The copied field schema and the digest of its canonical form. Both immutable
            // once written; App\Domain\Signing\Models\Envelope refuses to change either.
            $table->json('field_schema');
            $table->char('field_schema_sha256', 64);

            // Rendering settings snapshotted alongside the schema, so a template change
            // cannot alter how an in-flight envelope is drawn.
            $table->json('render_settings');

            $table->string('consent_policy_version', 64);
            // 'pades-b-b' | 'pades-b-t'; App\Domain\Evidence\Sealing\AssuranceLevel.
            $table->string('assurance_level', 32);
            // 'sequential' | 'parallel'.
            $table->string('signing_mode', 16);

            // draft|sent|in_progress|finalizing|completed|cancelled|declined|expired|finalization_failed
            $table->string('state', 32);
            $table->unsignedBigInteger('version')->default(1);

            $table->unsignedInteger('expiration_hours')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            // Computed at send from sent_at + expiration_hours; null while a draft.
            $table->timestamp('expires_at')->nullable();
            // Set by the first acceptance, or at send in parallel mode. Once set, only
            // signer-specific fields of unsigned recipients may still change (invariant 4).
            $table->timestamp('content_frozen_at')->nullable();

            // Opaque reference to the published final artifact, supplied by the finalizer.
            // A completed envelope always has one; the artifact table itself is issue #28.
            $table->string('artifact_ref', 512)->nullable();
            $table->string('finalization_failure_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'state']);
            $table->index('state');
            $table->index('expires_at');
            $table->index('source_template_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
