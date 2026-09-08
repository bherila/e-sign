<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One published evidence object: the executed PDF, the completion report, or the machine
 * evidence document.
 *
 * A row here is the assertion docs/ARCHITECTURE.md invariant 5 rests on — "a completed
 * envelope has a durable, validated final PDF and a complete evidence reference". It is
 * written in the same transaction as `markCompleted()`, and only after the bytes it names
 * have been written *and read back* through the storage adapter with a matching digest. The
 * row therefore never describes an object that is not there.
 *
 * ## Immutability, and why it is not a trigger
 *
 * A published artifact is never edited and never deleted, enforced in
 * App\Domain\Evidence\Finalization\Artifacts\Artifact for the portability reason
 * `document_revisions`, `recipient_attestations`, and `esign_audit_events` all record: a
 * trigger does not behave identically on SQLite, MySQL 8, and MariaDB. A correction is a new
 * generation with new keys, never a rewrite.
 *
 * ## The key layout carries the "never overwritten" rule
 *
 *     envelopes/{workspace public id}/{envelope public id}/{kind}-{sha256}.{pdf|json}
 *
 * Content-addressed, exactly like `documents/…` (App\Domain\Preparation\Documents\
 * DocumentStorageKey): different bytes are a different key, so a write can only ever replace
 * an object with itself and the retained original is untouchable by construction. The
 * workspace prefix is a tenancy boundary the staging pruner and any export can reason about
 * without a join.
 *
 * `sha256` is this application's own digest over the bytes, computed outside the PDF. An
 * object store's ETag is never used for it (docs/HANDOFF.md section 12): an ETag is a
 * transfer checksum whose algorithm depends on how the object was uploaded.
 *
 * `generation` ties the artifact to the `finalization_runs` attempt that produced it, so a
 * retried finalization is auditable rather than indistinguishable from the first try.
 * `seal_key_id` and `seal_certificate_sha256` are recorded on every artifact so a document
 * sealed before a key rotation stays attributable to the material that sealed it (issue #29;
 * docs/operations/seal-key-management.md). They are empty for artifacts that carry no seal,
 * which is a fact about the artifact rather than a missing value.
 *
 * No cascades. Executed evidence outlives the envelope's workspace membership and any
 * retention decision is explicit, never a side effect of deleting something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            // 'executed_pdf' | 'completion_report' | 'evidence_json'.
            // A string rather than a native ENUM, so the column reads identically on all
            // three supported engines (docs/adr/0002-supported-databases.md).
            $table->string('kind', 32);

            // A disk *name* resolved through config/filesystems.php. Code never branches on
            // the driver behind it and nothing presigns it (docs/BLOB_STORAGE.md rule 1).
            $table->string('disk', 64);
            $table->string('path', 512);

            $table->char('sha256', 64);
            $table->unsignedBigInteger('bytes');

            // The finalization attempt that produced these bytes.
            $table->unsignedInteger('generation');

            // Which seal material produced the artifact, and the certificate that verifies
            // it. Empty for an unsealed artifact (the evidence JSON, the report).
            $table->string('seal_key_id', 128)->default('');
            $table->string('seal_certificate_sha256', 64)->default('');

            // The PAdES level the *bytes* were read back at, never the level requested.
            $table->string('assurance_level_reached', 32)->nullable();

            // App\Domain\Evidence\Sealing\ValidationReport as stored: what the artifact
            // turned out to be when it was re-read, not what it was asked to be.
            $table->json('validation_report')->nullable();

            // Set in the publishing transaction. A row without it is not published, which
            // is what makes an object with no row provably unreferenced staging.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // At most one artifact of each kind per envelope, as a database fact rather
            // than as a rule someone has to remember. A row is only ever inserted in the
            // publishing transaction, so this is the last line of "exactly one publication
            // wins" underneath the envelope lock and the state re-check: two finalization
            // attempts that both reached the insert cannot both succeed, whatever the
            // engine's locking does.
            $table->unique(['envelope_id', 'kind'], 'artifacts_envelope_id_kind_unique');
            $table->unique(['disk', 'path'], 'artifacts_disk_path_unique');
            $table->index(['envelope_id', 'published_at']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
