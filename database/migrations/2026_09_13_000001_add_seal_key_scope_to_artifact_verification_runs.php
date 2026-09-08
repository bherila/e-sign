<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make artifact verification aware of seal key rotation (issue #29).
 *
 * Two changes, both additive.
 *
 * `artifact_verification_runs.seal_key_id` records the `--key-id` filter a run was narrowed
 * to, for the same reason `workspace_public_id` and `published_since` are already recorded: a
 * run that looked only at the artifacts sealed under the retired key is not evidence about
 * the ones sealed under the active key, and the readiness probe has to be able to tell.
 *
 * `unresolvable_keys` is a fourth problem count, kept separate from `invalid_signatures`
 * because it is a different fact about the deployment. An invalid signature says the bytes
 * changed. An unresolvable key says the bytes are fine and this deployment can no longer say
 * who sealed them — a certificate went missing from `ESIGN_SEAL_RETIRED_KEYS`, not an
 * integrity failure. Folding the two together would report a missing certificate as
 * corruption and send an operator looking for the wrong problem.
 *
 * The index on `artifacts.seal_key_id` serves both the `--key-id` filter and the
 * `signing_material` probe's "does any artifact name a key this deployment cannot resolve"
 * question, which is a `DISTINCT` over that column on every readiness check.
 *
 * Both new columns default to 0 / NULL, so rows written before this migration keep meaning
 * exactly what they meant: an unfiltered run that found no unresolvable keys because nothing
 * was looking for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifact_verification_runs', function (Blueprint $table): void {
            $table->string('seal_key_id', 128)->nullable()->after('published_since');
            $table->unsignedInteger('unresolvable_keys')->default(0)->after('invalid_signatures');
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->index('seal_key_id');
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropIndex(['seal_key_id']);
        });

        Schema::table('artifact_verification_runs', function (Blueprint $table): void {
            $table->dropColumn(['seal_key_id', 'unresolvable_keys']);
        });
    }
};
