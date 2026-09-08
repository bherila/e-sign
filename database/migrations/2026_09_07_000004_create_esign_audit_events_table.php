<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only application audit events.
 *
 * auth-laravel owns `auth_audit_log`, but its AuthAuditLogger contract is shaped entirely
 * around authentication events (login, passkey, 2FA, password) and has no method for an
 * arbitrary domain action, so provisioning writes here instead. Authentication events stay
 * in the package's table; this one records what the application did.
 *
 * Append-only by design: there is a `created_at` and no `updated_at`, and
 * App\Domain\Identity\Audit\AuditEvent refuses updates and deletes. Enforcement is in the
 * model rather than a database trigger because triggers are not portable across SQLite,
 * MySQL, and MariaDB; a deployment that wants hard enforcement grants the application user
 * INSERT and SELECT only on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_audit_events', function (Blueprint $table): void {
            $table->id();
            // Who acted. 'cli' for console provisioning, 'user' for an authenticated actor,
            // 'system' for a queue worker. Never a bare email.
            $table->string('actor_type', 32);
            $table->string('actor_id', 191)->nullable();
            $table->string('actor_label')->nullable();
            $table->string('action', 128);
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_audit_events');
    }
};
