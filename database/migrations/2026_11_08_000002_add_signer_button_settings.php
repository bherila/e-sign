<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which buttons a signer sees: Decline, and Finish later (issue #165).
 *
 * Nullable booleans at three levels — workspace, template, envelope — where null means "not
 * decided here", resolved most specific first and then the deployment default in
 * `config('esign.signing.allow_decline' | 'esign.signing.show_save_for_later')`, both true
 * (App\Domain\Signing\Envelopes\SignerButtons). The same rule `require_otp` follows.
 *
 * A template's values are copied onto an envelope when it is created from it, so editing the
 * template later does not change an agreement already built. Neither column is an
 * Envelope::SNAPSHOT_COLUMNS member: they govern what the signing page offers, not what the
 * agreement says.
 *
 * Expand-only: every existing row reads null, which resolves to today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['workspaces', 'templates', 'envelopes'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('allow_decline')->nullable();
                $blueprint->boolean('show_save_for_later')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['envelopes', 'templates', 'workspaces'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['allow_decline', 'show_save_for_later']);
            });
        }
    }
};
