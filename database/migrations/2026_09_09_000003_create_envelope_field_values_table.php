<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The current value of one field of the copied schema.
 *
 * One row per `(envelope_id, schema_field_id)`, enforced by a unique key: a field has one
 * value, and a correction overwrites it while the envelope is still editable rather than
 * appending a second row that something downstream would have to choose between. What was
 * agreed to is not recovered from this table — it is bound by the digests on
 * `recipient_attestations`, which never change.
 *
 * `recipient_id` is the field's *owner*, taken from the schema. `set_by` records who
 * actually wrote the value: `sender` for a prefill or a sender correction, `recipient` for
 * something the owner typed. They differ routinely — a sender prefills a read-only field
 * the recipient can see but not edit.
 *
 * `frozen_at` is stamped on every material value the moment content freezes (the first
 * acceptance, or send in parallel mode). A frozen value cannot change; only signer-specific
 * fields of recipients who have not signed yet may still be written
 * (docs/ARCHITECTURE.md invariant 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();
            // The owning recipient from the schema. Nullable only for a field whose owner
            // has been removed from an envelope, which cannot happen today.
            $table->foreignId('recipient_id')->nullable()->constrained('envelope_recipients')->restrictOnDelete();

            $table->string('schema_field_id', 191);

            // The value in its native JSON shape: a string for text and signatures, a
            // boolean for a checkbox, an ISO date string for a date.
            $table->json('value');
            // sha256 of the canonical JSON encoding of `value`, so a value can be compared
            // and referenced without reading it.
            $table->char('value_sha256', 64);

            // 'sender' | 'recipient'
            $table->string('set_by', 16);

            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'schema_field_id']);
            $table->index(['envelope_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_field_values');
    }
};
