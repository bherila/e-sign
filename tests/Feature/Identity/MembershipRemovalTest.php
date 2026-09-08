<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The no-cascade rule.
 *
 * Removing a person's access removes exactly their access. Executed instruments and the
 * historical evidence of who signed what outlive both the membership and the login: an
 * envelope somebody signed in 2026 must still be there, unaltered, after their account is
 * revoked in 2027. So:
 *
 *  - deleting a membership deletes one row and nothing else,
 *  - deleting an identity binding revokes login and touches nothing else,
 *  - `workspaces.id` may only be referenced with RESTRICT, so a workspace holding evidence
 *    cannot be hard-deleted at all — workspaces are soft-deleted.
 *
 * The RESTRICT half is asserted against a probe table standing in for the future
 * `envelopes` table, because the rule has to be true before that table is written, not
 * discovered afterwards.
 */
class MembershipRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_membership_removes_only_that_row(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $binding = IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => 'example-provider',
            'subject' => 'subject-1',
        ]);
        $membership = WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Sender,
        ]);
        app(AuditRecorder::class)->record(
            AuditActor::user($user),
            'test.probe',
            $workspace,
        );

        $membership->delete();

        $this->assertDatabaseCount('workspace_memberships', 0);
        $this->assertModelExists($workspace->fresh());
        $this->assertModelExists($user->fresh());
        $this->assertModelExists($binding->fresh());
        $this->assertDatabaseCount('esign_audit_events', 1);

        // Access is gone, and only access.
        $this->assertFalse($user->fresh()->can(WorkspacePermission::View->value, $workspace->fresh()));
    }

    public function test_deleting_an_identity_binding_revokes_login_without_touching_anything_else(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $binding = IdentityBinding::create([
            'user_id' => $user->getKey(),
            'issuer' => 'example-provider',
            'subject' => 'subject-1',
        ]);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Owner,
        ]);

        $binding->delete();

        $this->assertDatabaseCount('identity_bindings', 0);
        $this->assertDatabaseCount('workspace_memberships', 1);
        $this->assertModelExists($user->fresh());
    }

    public function test_a_future_envelopes_workspace_id_foreign_key_must_restrict_and_does(): void
    {
        $workspace = Workspace::factory()->create();

        // Stands in for `envelopes` (issue #13 and beyond). Any table that can hold an
        // executed instrument or its evidence declares its workspace foreign key exactly
        // like this. If this assertion ever fails, a schema change has made it possible to
        // delete signed agreements by deleting a workspace row.
        Schema::create('restrict_rule_probe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
        });

        DB::table('restrict_rule_probe')->insert(['workspace_id' => $workspace->getKey()]);

        try {
            $workspace->forceDelete();
            $this->fail('A hard delete of a workspace referenced by evidence must be refused by the database.');
        } catch (QueryException) {
            // Expected: RESTRICT.
        }

        $this->assertDatabaseCount('restrict_rule_probe', 1);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->getKey()]);

        Schema::drop('restrict_rule_probe');
    }

    public function test_a_workspace_with_memberships_cannot_be_hard_deleted(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Owner,
        ]);

        try {
            $workspace->forceDelete();
            $this->fail('workspaces.id is referenced with RESTRICT; a hard delete must be refused.');
        } catch (QueryException) {
            // Expected.
        }

        $this->assertDatabaseCount('workspace_memberships', 1);
    }

    public function test_soft_deleting_a_workspace_leaves_memberships_intact(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Owner,
        ]);

        $workspace->delete();

        $this->assertSoftDeleted($workspace);
        $this->assertDatabaseCount('workspace_memberships', 1);
        $this->assertNotNull(Workspace::withTrashed()->find($workspace->getKey())?->membershipFor($user));
    }

    public function test_a_user_row_referenced_by_a_membership_cannot_be_deleted(): void
    {
        // Deleting a user is a retention/privacy decision with its own reviewed policy, not
        // a side effect of revoking access. Revoking access deletes the membership.
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Auditor,
        ]);

        try {
            $user->delete();
            $this->fail('users.id is referenced by workspace_memberships with RESTRICT.');
        } catch (QueryException) {
            // Expected.
        }

        $this->assertDatabaseCount('workspace_memberships', 1);
    }

    public function test_no_table_references_a_membership_id(): void
    {
        // The structural reason the no-cascade rule holds: there is nothing downstream of a
        // membership to cascade into. A future "who did this" column stores user_id.
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            foreach (Schema::getForeignKeys($name) as $foreignKey) {
                $this->assertNotSame(
                    'workspace_memberships',
                    $foreignKey['foreign_table'],
                    "{$name} references workspace_memberships; removing access could then cascade beyond the membership row.",
                );
            }
        }
    }
}
