<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only record of one person's assent.
 *
 * An attestation is the acceptance: it binds the exact document bytes, the exact field
 * schema, the exact material values, the consent version that was displayed, and the
 * recipient session it was given in (docs/ARCHITECTURE.md invariant 2, docs/HANDOFF.md
 * section 8). None of those are references that could later move — they are digests.
 *
 * Append-only is enforced in App\Domain\Signing\Models\RecipientAttestation rather than by a
 * trigger, because triggers are not portable across SQLite, MySQL, and MariaDB; the same
 * choice `esign_audit_events` and `document_revisions` make. There is a `created_at` and no
 * `updated_at`: the row is written once.
 *
 * `prev_attestation_sha256` chains each acceptance to the previous one on the same envelope,
 * so removing or reordering an acceptance is detectable. The chain is not an independent
 * witness — it lives in a database an administrator can write to — and docs/HANDOFF.md
 * section 8 says so plainly. Off-host checkpoints are a separate deliverable.
 *
 * The unique key on `(recipient_id, session_ref)` is what makes retried acceptance idempotent
 * (invariant 7): a repeated submission in the same session finds the existing row instead of
 * writing a second logical acceptance, and two concurrent retries cannot both insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_attestations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('recipient_id')->constrained('envelope_recipients')->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            // What was agreed to, as digests rather than as references.
            $table->char('document_sha256', 64);
            $table->char('field_schema_sha256', 64);
            $table->char('material_values_sha256', 64);
            $table->string('consent_policy_version', 64);

            // The recipient session the assent was given in. Opaque to this module; the
            // guest access work (issue #25) owns what it means.
            $table->string('session_ref', 191);

            // Server time, never a client clock.
            $table->timestamp('accepted_at');

            // How the person was checked: email_link, email_otp, trusted_assertion,
            // authenticated_user. Not a claim that any of them proves identity.
            $table->string('verification_method', 32);

            // Minimized network/client evidence. Not conclusive identity proof
            // (docs/HANDOFF.md section 8).
            $table->json('client_evidence');

            // Null for the first acceptance on an envelope.
            $table->char('prev_attestation_sha256', 64)->nullable();
            $table->char('attestation_sha256', 64);

            $table->timestamp('created_at')->nullable();

            $table->unique(['recipient_id', 'session_ref']);
            $table->unique('attestation_sha256');
            $table->index(['envelope_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_attestations');
    }
};
