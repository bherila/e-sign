<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for the native API's mutating calls.
 *
 * One row per (credential, key). The row is claimed *before* the request runs and completed
 * after it, so two concurrent retries of the same call cannot both create an envelope: the
 * second loses the unique index and is told the first is still in flight.
 *
 * `request_hash` is what makes a replay safe. A key that comes back with a different request
 * is a client bug — usually a key reused across two different calls — and is refused rather
 * than answered with the first call's response, which would be a silent no-op on the second.
 *
 * `response_body` holds the exact JSON that was sent, so a replay is byte-identical to the
 * original. It is nullable while the request is in flight and stays null if the request
 * failed: only successful responses are replayable, because replaying a transient 500 would
 * make it permanent.
 *
 * Rows expire 24 hours after they were created and are removed by
 * `esign:api:prune-idempotency-keys`, scheduled hourly in routes/console.php.
 *
 * The foreign key is `cascadeOnDelete` rather than `restrictOnDelete`: unlike an envelope or
 * an audit event, an idempotency record is operational scratch with a day's lifetime and
 * must never be the reason a credential row cannot be removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('service_credentials')->cascadeOnDelete();

            // Client-chosen. 255 is generous for a UUID and bounded enough that a key
            // cannot be used as a storage channel.
            $table->string('key', 255);

            // sha256 of the canonicalised method, path, and body.
            $table->string('request_hash', 64);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // The claim. Two concurrent requests with one key race here and exactly one wins.
            $table->unique(['credential_id', 'key']);

            // The prune command's access path.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
