<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local identities bind to a stable (issuer, subject) tuple, never to an email address.
 *
 * `issuer` is the identity provider this subject is meaningful within — for the auth-laravel
 * OAuth client that is `config('bherila-auth.oauth_client.provider')`, matched against the
 * `provider` field of BWH\Auth\OAuth\OAuthIdentity. `subject` is that provider's opaque,
 * stable subject identifier. Email is contact data: it changes, it is reused, and it is not
 * an account-linking key. Uniqueness is enforced in the database so two rows can never claim
 * the same provider subject.
 *
 * Column widths: 191 characters each keeps the composite unique index within the 767-byte
 * per-column prefix limit of older InnoDB row formats under utf8mb4, so the same migration
 * runs on SQLite, MySQL 8, and MariaDB (docs/adr/0002-supported-databases.md).
 *
 * `last_seen_at` is written by the login flow (issue #12), not by provisioning. A binding
 * created by `esign:bootstrap-owner` therefore has a null `last_seen_at` until the owner
 * actually signs in, which is exactly the signal the runbook's verification step reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_bindings', function (Blueprint $table): void {
            $table->id();
            // Revoking login access deletes the binding. That must not touch evidence, and
            // it cannot: nothing references identity_bindings.id.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('issuer', 191);
            $table->string('subject', 191);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_bindings');
    }
};
