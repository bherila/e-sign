<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a workspace wants its events delivered.
 *
 * The workspace foreign key is `restrictOnDelete()` for the reason
 * 2026_09_07_000001_create_workspaces_table.php gives: an endpoint carries the
 * delivery history of executed instruments, so it outlives the membership that
 * created it and cannot be swept away with its workspace.
 *
 * Both secrets are stored under Laravel's `encrypted` cast, so they are
 * ciphertext at rest under APP_KEY and never appear in a query log. Two of them,
 * because a rotation that invalidates the receiver's configured secret the
 * instant it happens is a rotation nobody performs: `secret_previous` keeps
 * verifying until `secret_previous_expires_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('url', 2048);
            $table->string('description')->nullable();
            // Null means every event. A list means only these event names, matched
            // exactly: no wildcards, so a new event family is never silently
            // delivered to an endpoint that never asked for it.
            $table->json('event_filter')->nullable();
            $table->text('secret_current');
            $table->text('secret_previous')->nullable();
            $table->timestamp('secret_previous_expires_at')->nullable();
            // A disabled endpoint is a visible state with a reason an operator can
            // read, never a silent drop.
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
