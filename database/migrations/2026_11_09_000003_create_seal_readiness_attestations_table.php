<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the worker found when it last opened the seal material (issue #37).
 *
 * In the Docker layout the private key is mounted into the worker alone, so the web role's send
 * gate cannot open it. The worker does, and records the outcome here, one row per key id:
 * the certificate's SHA-256 fingerprint and expiry, and for each assurance level either null
 * (available) or the reason it is not. The send gate trusts a row only while `checked_at` is
 * recent. Holds no key material and no path.
 *
 * `dateTime()` for the NOT NULL `checked_at` (AGENTS.md, Database safety rule 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seal_readiness_attestations', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 191)->unique();
            $table->char('certificate_sha256', 64)->nullable();
            $table->dateTime('certificate_not_after')->nullable();
            $table->json('levels');
            $table->string('material_error', 500)->nullable();
            $table->dateTime('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seal_readiness_attestations');
    }
};
