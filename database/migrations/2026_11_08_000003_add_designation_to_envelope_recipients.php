<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each party does on the envelope: signer, approver or cc (field schema 1.2, issue #169).
 *
 * Copied from the envelope's field schema when the recipient row is created, like the name and
 * address, so the row says what the snapshot said. Expand-only: every existing row is a signer,
 * which is what every party was before 1.2, so the default is exact rather than a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_recipients', function (Blueprint $table): void {
            $table->string('designation', 16)->default('signer')->after('schema_recipient_id');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_recipients', function (Blueprint $table): void {
            $table->dropColumn('designation');
        });
    }
};
