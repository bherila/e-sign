<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable preparation: a named thing a sender picks, whose content lives in its versions.
 *
 * The row itself carries no field definitions, no document, and no recipients. Everything a
 * sender actually sends is on a `template_versions` row, because that is the thing that has
 * to be immutable once it has been used (docs/HANDOFF.md section 6: "Later template changes
 * never mutate existing requests"). A template is the mutable handle; a version is the
 * frozen content.
 *
 * `workspace_id` is RESTRICT, per the no-cascade rule the memberships migration sets out: a
 * template outlives the membership that created it and the workspace that held it.
 * Templates are soft-deleted, so anything sweeping storage or evidence by template must read
 * this table through the query builder rather than Eloquent, which would hide trashed rows.
 *
 * `current_version_id` deliberately carries **no foreign key**. templates and
 * template_versions reference each other, and a circular constraint cannot be added to an
 * existing table on SQLite (there is no `ALTER TABLE ADD CONSTRAINT`), so declaring it on
 * one engine and not the others would mean the column behaves differently in development,
 * in CI's MySQL/MariaDB jobs, and in production. It is written only by
 * App\Domain\Preparation\Templates\TemplateService::publish(), inside the transaction that
 * stamps `published_at` on the version it points at, and it is only ever set to a version of
 * this template.
 *
 * `retired_at` retires a template from the picker without deleting anything: envelopes
 * already sent from its versions keep working, and their snapshots are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table): void {
            $table->id();
            // Public identifier used in URLs and API payloads so the autoincrement id is
            // never enumerable from outside.
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // The published version a sender gets when they pick this template. Null until
            // the first publish. See the class docblock for why this has no constraint.
            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->timestamp('retired_at')->nullable();

            // Who created it. RESTRICT for the same reason memberships are: deleting a user
            // row is a reviewed retention decision, never a side effect of revoking access.
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'retired_at']);
            $table->index('current_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
