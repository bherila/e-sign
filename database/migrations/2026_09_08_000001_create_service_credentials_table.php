<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service credentials: API callers as workspace-scoped principals.
 *
 * An API caller is not a person. It has no membership row, no role, and no identity
 * binding; it has one workspace, a fixed scope list, and a secret. Everything an
 * authenticated credential may do is decided from this row before any resource is looked up
 * (docs/HANDOFF.md section 10: "Enforce scope/tenant isolation before looking up
 * resources").
 *
 * Secret storage
 * --------------
 * `prefix` is the public half: it appears in the plaintext secret, in the console, in audit
 * events, and in logs, and it is unique, so authentication resolves exactly one candidate
 * row. `secret_hash` is `hash('sha256', secret_salt.':'.<full plaintext secret>)`, with a
 * fresh 128-bit `secret_salt` per row. The plaintext is never stored and never logged.
 *
 * A password-style hash (bcrypt/argon2 via Laravel's `Hash`) is deliberately not used here.
 * Its only advantage is cost against offline brute force of a low-entropy, human-chosen
 * secret, and there is no such secret in this table: the plaintext is 43 random characters
 * from a 36-character alphabet (~222 bits) minted by the server, so brute force is not the
 * threat being defended against. The cost would instead be paid on the hot path of every
 * API request — verification compares one row, found by prefix, so there is no wide scan to
 * amortise it over — and bcrypt additionally truncates its input at 72 bytes and needs
 * rehash-on-verify plumbing. The salt is still per row so that a stolen table yields no
 * precomputation and no cross-row comparison. Verification uses `hash_equals`.
 * See App\Domain\Identity\Credentials\CredentialSecret.
 *
 * Deletion rules (matching workspace_memberships' no-cascade rule)
 * ---------------------------------------------------------------
 *  - `workspace_id` is RESTRICT. A workspace that still has credentials cannot be
 *    hard-deleted out from under them; workspaces are soft-deleted.
 *  - `rotated_from_id` is a provenance pointer to the credential this one replaced, and is
 *    `nullOnDelete()`: a retention deletion of an ancient predecessor must not be blocked by
 *    its successor, and the real history is in `esign_audit_events` regardless.
 *  - Nothing else references `service_credentials.id`. Revoking a credential is an update to
 *    `revoked_at`, never a delete, so it can never reach an envelope or an audit event.
 *
 * `scopes` is a JSON array of App\Domain\Identity\Credentials\Scope values. It is a plain
 * JSON column rather than a normalised table or a native ENUM so the same migration runs on
 * SQLite, MySQL 8, and MariaDB (docs/adr/0002-supported-databases.md) and so adding a scope
 * is not a schema change. The enum is the authority on which values are legal; unknown
 * scopes are rejected at issue time, not by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            // Operator-facing description ("consumer production", "migration import").
            $table->string('label');
            // Public identifier, `esk_` plus 12 characters. Safe to print and log.
            $table->string('prefix', 32)->unique();
            $table->string('secret_salt', 64);
            $table->string('secret_hash', 128);
            $table->json('scopes');
            // Written by the authentication middleware, throttled to at most once a minute
            // per credential so a busy integration does not turn every API call into a write.
            $table->timestamp('last_used_at')->nullable();
            // Null means "no expiry". Rotation sets this on the predecessor to end the
            // overlap window.
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('rotated_from_id')->nullable()->constrained('service_credentials')->nullOnDelete();
            // Revocation is immediate and irreversible: there is no un-revoke, only a new
            // credential.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_credentials');
    }
};
