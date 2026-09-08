<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A membership is the only source of workspace authority. One row per (workspace, user).
 *
 * No-cascade rule
 * ---------------
 * Removing a person's membership removes exactly this row. It must never delete, anonymise,
 * or rewrite an envelope, an artifact, or an audit event they touched: executed instruments
 * and historical signer evidence outlive access. Concretely:
 *
 *  - nothing references `workspace_memberships.id`, so deleting a membership can cascade
 *    nowhere. Any future table that wants to record "who did this" stores `user_id`
 *    (nullable, `nullOnDelete()` at most) rather than a membership id.
 *  - `workspace_id` is RESTRICT: a workspace with memberships cannot be hard-deleted out
 *    from under them. Workspaces are soft-deleted.
 *  - future `envelopes.workspace_id`, `artifacts.workspace_id`, and every evidence-bearing
 *    foreign key MUST also be `->restrictOnDelete()`.
 *  - `user_id` is RESTRICT too. Deleting a user row is a privacy/retention decision with its
 *    own reviewed policy, not a side effect of revoking access; revoking access means
 *    deleting the membership and the identity binding, which leaves evidence untouched.
 *
 * `role` is a plain string, not a native ENUM, so the column behaves identically on SQLite,
 * MySQL 8, and MariaDB and so adding a role is not a schema migration. The application enum
 * App\Domain\Identity\Enums\WorkspaceRole is the authority; a CHECK constraint would have to
 * be dropped and recreated on SQLite for every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32)->default(WorkspaceRole::Auditor->value);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
    }
};
