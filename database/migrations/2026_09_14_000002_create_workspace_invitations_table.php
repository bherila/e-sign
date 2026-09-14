<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single-use invitation to join a workspace in a stated role (issue #110).
 *
 * The invitation is how a person who has never signed in is given a role without anything
 * matching on an email address: whoever redeems the link, signed in as whoever they are, is
 * the person the membership binds to. Identity still comes only from `(issuer, subject)` or a
 * local account.
 *
 * Only a digest of the token is stored. The link is shown once, to the owner or administrator
 * who created it; a database read gives nobody a working link.
 *
 * Foreign keys follow the membership table's no-cascade rule. `workspace_id` is RESTRICT. The
 * three user references are nullable and `nullOnDelete()`: they record who did something, and
 * deleting a user row is a retention decision of its own, never blocked by an invitation and
 * never cascading into one. Every time column is `dateTime()`, never a bare `timestamp()`
 * (AGENTS.md, database rule 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('role', 32);

            // sha256 of the token in the link, hex. The token itself is never stored.
            $table->string('token_sha256', 64)->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('expires_at');

            $table->dateTime('redeemed_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // The members page's access path: a workspace's invitations that are still open.
            $table->index(['workspace_id', 'redeemed_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
