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

            // Compared byte-for-byte: the field schema's identifier grammar allows `notes`
            // and `Notes` to be two different fields, possibly owned by two different
            // recipients. See binaryCollation() below.
            $table->string('schema_field_id', 191)->collation($this->binaryCollation());

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

    /**
     * The collation that makes a string column compare byte-for-byte, or null where the
     * engine already does.
     *
     * The connection default is `utf8mb4_unicode_ci`, under which MySQL and MariaDB treat
     * `notes` and `Notes` as the same value. SQLite's default is binary, so it treats them
     * as two. Schema identifiers and session references are exact tokens, and a unique key
     * over them that means different things on different engines is precisely the collation
     * assumption docs/adr/0002-supported-databases.md rules out — here it would let one
     * recipient's value overwrite another's, or two sessions collapse into one acceptance.
     *
     * Driver-conditional rather than a literal, because `utf8mb4_bin` is not a collation
     * SQLite knows and Laravel's SQLite grammar emits the `collate` clause verbatim. The CI
     * `database` job runs this migration on both engines, which is what
     * docs/adr/0002-supported-databases.md asks of an engine-specific choice.
     */
    private function binaryCollation(): ?string
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'utf8mb4_bin'
            : null;
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_field_values');
    }
};
