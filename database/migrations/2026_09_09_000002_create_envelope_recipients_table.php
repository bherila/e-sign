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

            // The id used by the copied schema; unique within one envelope.
            $table->string('schema_recipient_id', 191);

            $table->string('name');
            $table->string('email', 191);
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

    public function down(): void
    {
        Schema::dropIfExists('envelope_recipients');
    }
};
