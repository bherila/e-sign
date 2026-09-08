<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credential one recipient uses to reach one envelope.
 *
 * docs/HANDOFF.md section 8: "Use high-entropy expiring invitation/session credentials,
 * scoped to one recipient and envelope. Store bearer-token verifiers rather than plaintext
 * reusable tokens where possible; design rotation/reissuance explicitly."
 *
 * So this table holds `token_hash` and never the token. The plaintext is 256 bits of
 * `random_bytes`, returned exactly once to the caller that will mail it, and afterwards
 * nothing in this deployment — not a database dump, not a support screen, not a log line —
 * can reconstruct it. The hash is a bare SHA-256 rather than a password hash on purpose:
 * the input is full-entropy random, so there is nothing for a slow KDF to protect against,
 * and a unique index over a deterministic digest is what makes lookup a single indexed read
 * instead of a scan that compares every row.
 *
 * Three separate nullable timestamps rather than one status column, because they answer
 * three different questions and can be true at once: `consumed_at` says a session was
 * started with it, `revoked_at` says it was superseded or withdrawn, `expires_at` says the
 * clock ran out. A row is live only when all three say so, and
 * App\Domain\Signing\Sessions\InvitationIssuer is the only thing that decides that.
 *
 * `envelope_id` is stored alongside `recipient_id` even though the recipient already
 * determines it. The URL carries both, and checking the pair means a token minted for one
 * envelope cannot be replayed against another by editing the path — the mismatch is caught
 * on the row rather than by trusting a join.
 *
 * No cascades: an invitation is part of the account of how a signature came to be given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('recipient_id')->constrained('envelope_recipients')->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            // SHA-256 of the plaintext token, hex. Unique, so two rows can never claim the
            // same credential and a lookup is one indexed read.
            $table->char('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();

            // The workspace user who issued it, where there was one. Null for an invitation
            // minted by the service itself — the legacy resolver does exactly that after a
            // mailbox check, and recording a fake actor would be worse than recording none.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['recipient_id', 'expires_at']);
            $table->index(['envelope_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_invitations');
    }
};
