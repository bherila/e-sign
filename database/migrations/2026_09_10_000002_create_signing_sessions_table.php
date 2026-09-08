<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One guest's authorized period on one envelope.
 *
 * A session is what an invitation becomes when somebody explicitly asks for it with a POST.
 * Nothing a GET does creates one (AGENTS.md, "GET is harmless"), and every route past the
 * landing page requires one, so a public recipient ULID on its own authorizes nothing
 * (docs/HANDOFF.md section 8).
 *
 * `session_token_hash` is the verifier for the value in the `esign_signing` cookie, stored
 * the same way and for the same reason as `recipient_invitations.token_hash`: the plaintext
 * exists in the browser and nowhere on the server.
 *
 * `ip_hash` and `user_agent_hash` are keyed digests, not addresses. They exist to answer
 * "did this request come from the same place the session was started from?" — a
 * corroboration question — and hashing them means the answer survives without the table
 * accumulating a browsing history. The unhashed address still reaches
 * `recipient_attestations.client_evidence`, once, at the moment of assent, because
 * docs/HANDOFF.md section 8 asks the evidence record to capture minimized network evidence;
 * it is never treated as identity proof in either place.
 *
 * `public_id` is what the attestation's `session_ref` holds. It is the idempotency key for
 * an acceptance, so it must be stable, opaque, and never the cookie value.
 *
 * `expires_at` slides: each authorized request pushes it out by the configured window, and
 * a request that arrives after it has passed is refused rather than renewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_sessions', function (Blueprint $table): void {
            $table->id();
            // Quoted verbatim as recipient_attestations.session_ref.
            $table->ulid('public_id')->unique();
            $table->foreignId('recipient_id')->constrained('envelope_recipients')->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            // Which invitation was exchanged for this session. Kept so the credential chain
            // is readable end to end; nullable because an invitation may be pruned long
            // after the agreement it led to is executed.
            $table->foreignId('invitation_id')->nullable()->constrained('recipient_invitations')->nullOnDelete();

            $table->char('session_token_hash', 64)->unique();

            // Keyed digests of the client's address and user agent. Never the values.
            $table->char('ip_hash', 64);
            $table->char('user_agent_hash', 64);

            // 'link' or 'link+otp'. The wording is this module's; the attestation records
            // App\Domain\Signing\Envelopes\VerificationMethod, which is the evidence model's
            // vocabulary, and SigningVerification maps one onto the other in one place.
            $table->string('verification_method', 32);

            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 32)->nullable();

            // A validated `?return=` destination, or null. Written only after the host has
            // been checked against the configured allowlist, so nothing unvalidated is ever
            // stored where a later request could redirect to it.
            $table->string('return_url', 2048)->nullable();

            $table->timestamps();

            $table->index(['envelope_id', 'recipient_id']);
            $table->index(['recipient_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_sessions');
    }
};
