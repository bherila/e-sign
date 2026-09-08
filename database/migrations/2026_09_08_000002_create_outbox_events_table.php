<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox: one row per logical event.
 *
 * A row is written inside the same database transaction as the domain
 * transition it describes (docs/HANDOFF.md §11), so an event cannot exist for a
 * transition that rolled back, and a committed transition cannot lose its event.
 *
 * The row is immutable. `public_id` is the stable event identity that survives
 * every retry and every replay, and `canonical_body` holds the exact JSON bytes
 * that are signed and sent: re-encoding at delivery time would produce a body
 * whose HMAC no longer matches what a receiver verifies. There is a `created_at`
 * and deliberately no `updated_at`; nothing updates this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('event_name', 128);
            // The `data` object of the envelope, kept queryable.
            $table->json('payload');
            // The whole envelope, byte-for-byte as signed and sent. longText
            // rather than text because MySQL's TEXT caps at 64 KB and a payload
            // that quietly exceeds it would be truncated into an unverifiable
            // body rather than rejected.
            $table->longText('canonical_body');
            // When the domain transition happened, which is not when the row was
            // written and not when a delivery was attempted. Two events delivered
            // out of order still carry the order in which they occurred.
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index('event_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
