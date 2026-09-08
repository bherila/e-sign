<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspaces are the tenancy boundary. Every template, envelope, credential, and evidence
 * record hangs off exactly one workspace.
 *
 * Deletion rule (see also 2026_09_07_000002_create_workspace_memberships_table.php):
 * a workspace is soft-deleted, and any future table that references `workspaces.id` and can
 * hold executed instruments or evidence — `envelopes.workspace_id` above all — MUST declare
 * its foreign key `->restrictOnDelete()`. Executed agreements and their audit trail outlive
 * both the membership that created them and the workspace that held them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            // Public identifier used in URLs and API payloads so the autoincrement id is
            // never enumerable from outside. ULIDs are 26 chars, lexicographically sortable.
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('slug', 191)->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
