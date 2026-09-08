<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a guest has to enter a mailed code as well as follow the link.
 *
 * Nullable on both tables, and null means "not decided here" rather than false.
 * App\Domain\Signing\Sessions\OtpRequirement resolves envelope, then workspace, then
 * `config('esign.signing.require_otp')`, so a deployment default is a default and an
 * envelope that says nothing does not silently overrule the workspace that sent it.
 *
 * `envelopes.require_otp` is deliberately *not* one of Envelope::SNAPSHOT_COLUMNS. The
 * snapshot columns are what a signature binds to — the document bytes, the field schema,
 * the consent version — and how the person was let in is not part of what they agreed to.
 * The check that was actually applied is recorded on the attestation as
 * `verification_method`, which is where the evidence for it belongs; a later change to this
 * flag cannot alter that record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('require_otp')->nullable()->after('slug');
        });

        Schema::table('envelopes', function (Blueprint $table): void {
            $table->boolean('require_otp')->nullable()->after('consent_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('require_otp');
        });

        Schema::table('envelopes', function (Blueprint $table): void {
            $table->dropColumn('require_otp');
        });
    }
};
