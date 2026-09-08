<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One party on one envelope, created from the copied field schema's `recipients` and
 * `signing_order` and never from a template afterwards.
 *
 * `schema_recipient_id` is the id the copied schema uses (`buyer`, `counterparty`); it is
 * how a field finds its owner. Field ownership is by recipient id, never by signing order
 * and never by email address (AGENTS.md, docs/HANDOFF.md section 2).
 *
 * `order_index` is the 1-based signing stage from `signing_order`. In parallel mode there
 * is exactly one stage, which the envelope factory enforces rather than silently ignoring a
 * multi-stage order.
 *
 * Recipient progress is modelled independently of envelope completion (docs/HANDOFF.md
 * section 6): a recipient reaches `signed` long before the envelope reaches `completed`.
 *
 * `version` is this row's own compare-and-swap token, separate from the envelope's, so two
 * recipients acting at once do not collide on a single counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_recipients', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            // The id used by the copied schema; unique within one envelope, and compared
            // byte-for-byte. See binaryCollation() below.
            $table->string('schema_recipient_id', 191)->collation($this->binaryCollation());

            $table->string('name');
            // 320, the longest address the field schema accepts
            // (FieldSchemaValidator::EMAIL_MAX_LENGTH). A narrower column would truncate a
            // long address on a permissive engine — sending the invitation somewhere other
            // than the address recorded on the agreement — and error on a strict one.
            $table->string('email', 320);
            $table->unsignedInteger('order_index');

            // pending|active|signed|declined
            $table->string('state', 16);
            $table->unsignedBigInteger('version')->default(1);

            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();

            // Who this party was recorded as when the envelope was created: name, email,
            // display role, and any claimed organizational capacity. A snapshot, because the
            // schema copy is immutable but the underlying person's details are not.
            $table->json('identity_snapshot');

            $table->timestamps();

            $table->unique(['envelope_id', 'schema_recipient_id']);
            $table->index(['envelope_id', 'order_index']);
            $table->index(['envelope_id', 'state']);
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
        Schema::dropIfExists('envelope_recipients');
    }
};
