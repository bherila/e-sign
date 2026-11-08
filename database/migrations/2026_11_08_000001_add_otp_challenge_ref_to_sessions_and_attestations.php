<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which one-time code verified a signing session, and so the acceptance made in it (issue #166).
 *
 * The reference is the challenge's public id, not a foreign key: a challenge row can be purged
 * by retention while the attestation that cites it must not lose the citation, and an
 * attestation is evidence that outlives the credential that admitted its author.
 *
 * Expand-only and nullable on both tables, so the previous release keeps working against the
 * new schema and every existing row reads as "no code was involved", which is true of every
 * row written before this column existed only when its session did not use one — those rows
 * simply carry no reference, and nothing claims otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_sessions', function (Blueprint $table): void {
            $table->string('otp_challenge_ref', 26)->nullable()->after('verification_method');
        });

        Schema::table('recipient_attestations', function (Blueprint $table): void {
            $table->string('otp_challenge_ref', 26)->nullable()->after('verification_method');
        });
    }

    public function down(): void
    {
        Schema::table('recipient_attestations', function (Blueprint $table): void {
            $table->dropColumn('otp_challenge_ref');
        });

        Schema::table('signing_sessions', function (Blueprint $table): void {
            $table->dropColumn('otp_challenge_ref');
        });
    }
};
