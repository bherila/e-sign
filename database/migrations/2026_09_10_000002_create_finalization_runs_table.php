<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to publish an envelope's artifacts, and how far it got.
 *
 * This table is what makes the staged publication in docs/ARCHITECTURE.md recoverable rather
 * than merely careful. The expensive, uninterruptible-looking part of finalization — render,
 * seal, validate, upload — happens *outside* any lock, so a worker can die in the middle of
 * it. The run row is the only thing that survives that death, and it carries the two facts a
 * retry needs:
 *
 *  - `input_snapshot`: the immutable inputs the attempt was made from (document revision and
 *    its digest, field schema digest, per-field value digests, attestation ids). Two runs
 *    with the same snapshot were rendering the same document from the same evidence, which
 *    is what makes reusing already-uploaded bytes safe instead of a guess.
 *  - `outputs`: what the attempt actually managed to upload — disk, key, digest, byte count
 *    and seal metadata per artifact. A run that reached `uploaded` and then crashed leaves
 *    provably good bytes behind; the retry publishes those rather than re-sealing, which
 *    would otherwise burn a second timestamp and produce a second set of objects.
 *
 * `generation` is allocated under the envelope lock and is unique per envelope. It is the
 * compare-and-swap token for publication: the publishing transaction re-reads the envelope,
 * re-checks that it is still `finalizing`, and refuses to publish from a run whose generation
 * has been superseded by a newer attempt.
 *
 * `error` is redacted before it is written. A finalization failure can carry a storage key,
 * a certificate subject, or a TSA URL, and this table is read by operators and, indirectly,
 * by anything that surfaces "why did this fail"; docs/HANDOFF.md section 8 requires the same
 * minimization the webhook and mail modules apply to their attempt logs.
 *
 * There is no foreign key to `artifacts`: a run describes an attempt, and most attempts that
 * matter operationally are the ones that produced no artifact at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finalization_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->restrictOnDelete();

            $table->unsignedInteger('generation');

            // 'started' | 'rendered' | 'uploaded' | 'published' | 'failed'.
            $table->string('state', 16);

            $table->json('input_snapshot');

            // Null until the attempt has uploaded something.
            $table->json('outputs')->nullable();

            // Redacted, and capped: an error message is a diagnosis, not a transcript.
            $table->string('error', 1000)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'generation'], 'finalization_runs_env_gen_unique');
            $table->index(['envelope_id', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finalization_runs');
    }
};
