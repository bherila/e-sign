<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One emailed one-time code, and everything that decides whether it may still be used.
 *
 * Be precise about what a mailbox code proves. The invitation link already went to that
 * address, so a code sent to the same address demonstrates *continued* access to the same
 * mailbox: a second check on one factor, not a second factor, and not identity
 * (docs/HANDOFF.md sections 8 and 9). It is recorded as `email_otp` for exactly that
 * reason — the evidence model distinguishes the checks rather than grading them.
 *
 * `code_hash` is a keyed digest, not a bare one. Six digits is a 20-bit space, so an
 * unkeyed hash of a leaked table is trivially reversed; keying it with the application's
 * own secret means the digest is useless without that secret. It is still stored rather
 * than compared in memory because the code has to survive between two HTTP requests.
 *
 * `attempts` is counted on the row, not per request, so guessing cannot be spread across
 * parallel connections. Reaching the ceiling burns the challenge: a new one has to be
 * requested, which is itself rate limited per address and per client address.
 *
 * `purpose` separates the two places a code is asked for — starting a session from an
 * invitation link, and the legacy `/signing/{recipientPublicId}` resolver — so a code
 * issued for one cannot be presented to the other.
 *
 * The destination address is not stored here. It is `envelope_recipients.email`, one join
 * away, and copying it would put a second mutable copy of a personal detail in a table
 * whose rows outlive their usefulness by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('recipient_id')->constrained('envelope_recipients')->restrictOnDelete();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            // 'session_start' | 'legacy_resolve'.
            $table->string('purpose', 32);

            // Keyed SHA-256 of the code. See the class docblock for why it is keyed.
            $table->char('code_hash', 64);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('burned_at')->nullable();

            // Which client asked for it, as a keyed digest. Corroboration only.
            $table->char('ip_hash', 64);

            $table->timestamps();

            $table->index(['recipient_id', 'purpose', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_otp_challenges');
    }
};
