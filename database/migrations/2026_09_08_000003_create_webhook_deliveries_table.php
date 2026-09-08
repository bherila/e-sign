<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per delivery attempt, kept separate from the logical event.
 *
 * Attempts are appended, never overwritten: attempt 3 of an event is a new row
 * with its own `public_id` (the per-attempt identity sent as `X-Firma-Delivery`)
 * and its own `signature_timestamp`, while the event's `public_id` — the thing a
 * receiver deduplicates on — stays the same. That is what makes the retry
 * history readable after the fact.
 *
 * `response_excerpt` and `error` are redacted and truncated before they are
 * written; a receiver that echoes an Authorization header must not turn our
 * diagnostics table into a credential store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('outbox_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('state', 16)->default('pending');
            $table->timestamp('attempted_at')->nullable();
            // The `t=` value in X-Firma-Signature for this attempt. Unix seconds,
            // fresh per attempt, so a receiver's replay window is measured against
            // when this attempt was signed and not when the event occurred.
            $table->unsignedBigInteger('signature_timestamp')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_excerpt', 1024)->nullable();
            $table->text('error')->nullable();
            // Set on a pending row: when the attempt it represents becomes due.
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['outbox_event_id', 'webhook_endpoint_id', 'attempt']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
