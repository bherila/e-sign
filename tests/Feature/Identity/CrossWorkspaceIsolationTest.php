<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Credentials\CurrentPrincipal;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-workspace isolation.
 *
 * A member of workspace A must not reach workspace B by any identifier it can guess or
 * read: the autoincrement id, the public ULID, or the slug. Every ability is denied and
 * every member-scoped lookup behaves as though B does not exist, so a probe cannot even
 * distinguish "forbidden" from "no such workspace".
 *
 * This is the seed of the isolation suite that issue #11 requires in CI. It covers the
 * policy and the member-scoped lookups. Service credentials have landed and their cases are
 * below; the HTTP half of that case (a request carrying a valid secret for another
 * workspace's resource) is in
 * tests/Feature/Identity/Credentials/ServiceCredentialAuthenticationTest.php, where the
 * middleware and its probe routes already are. A surface with its own HTTP routes asserts
 * the same property over those routes next to the rest of its coverage, and lists itself
 * here:
 *
 *  - documents, uploads and revision downloads:
 *    tests/Feature/Preparation/DocumentHttpTest.php
 *
 * The remaining surfaces named in issue #11 — imported-provider aliases, JSON import,
 * artifact downloads, and queue jobs — do not exist yet, and each one adds its cases here as
 * it lands rather than getting an isolation test of its own somewhere else.
 */
class CrossWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $alpha;

    private Workspace $beta;

    private User $alphaOwner;

    private User $betaOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Workspace::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->beta = Workspace::factory()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->alphaOwner = $this->memberOf($this->alpha, WorkspaceRole::Owner);
        $this->betaOwner = $this->memberOf($this->beta, WorkspaceRole::Owner);
    }

    public function test_owner_of_one_workspace_has_no_ability_in_the_other(): void
    {
        foreach (WorkspacePermission::cases() as $permission) {
            $this->assertTrue($this->alphaOwner->can($permission->value, $this->alpha));
            $this->assertFalse($this->alphaOwner->can($permission->value, $this->beta));

            $this->assertTrue($this->betaOwner->can($permission->value, $this->beta));
            $this->assertFalse($this->betaOwner->can($permission->value, $this->alpha));
        }
    }

    public function test_every_role_in_one_workspace_is_denied_in_the_other(): void
    {
        foreach (WorkspaceRole::cases() as $role) {
            $member = $this->memberOf($this->alpha, $role);

            foreach (WorkspacePermission::cases() as $permission) {
                $this->assertFalse(
                    $member->can($permission->value, $this->beta),
                    "{$role->value} of alpha must not be able to {$permission->value} beta",
                );
            }
        }
    }

    public function test_a_member_scoped_lookup_by_autoincrement_id_cannot_reach_the_other_workspace(): void
    {
        $found = Workspace::query()
            ->whereMemberOf($this->alphaOwner)
            ->whereKey($this->beta->getKey())
            ->first();

        $this->assertNull($found);

        $this->assertSame(
            $this->alpha->getKey(),
            Workspace::query()->whereMemberOf($this->alphaOwner)->whereKey($this->alpha->getKey())->first()?->getKey(),
        );
    }

    public function test_a_member_scoped_lookup_by_public_id_cannot_reach_the_other_workspace(): void
    {
        $this->assertNull(
            Workspace::query()
                ->whereMemberOf($this->alphaOwner)
                ->where('public_id', $this->beta->public_id)
                ->first(),
        );
    }

    public function test_a_member_scoped_lookup_by_slug_cannot_reach_the_other_workspace(): void
    {
        $this->assertNull(
            Workspace::query()
                ->whereMemberOf($this->alphaOwner)
                ->where('slug', 'beta')
                ->first(),
        );
    }

    public function test_the_member_scope_lists_only_the_callers_workspaces(): void
    {
        $this->assertSame(
            ['alpha'],
            Workspace::query()->whereMemberOf($this->alphaOwner)->pluck('slug')->all(),
        );

        $this->assertSame(
            ['beta'],
            Workspace::query()->whereMemberOf($this->betaOwner)->pluck('slug')->all(),
        );
    }

    public function test_the_member_scope_returns_nothing_for_an_unsaved_user(): void
    {
        // A guest signing session has no user row. It must not fall through to "no filter".
        $this->assertCount(0, Workspace::query()->whereMemberOf(new User)->get());
    }

    public function test_route_binding_uses_the_public_id_not_the_autoincrement_id(): void
    {
        $this->assertSame('public_id', $this->alpha->getRouteKeyName());
        $this->assertSame($this->alpha->public_id, $this->alpha->getRouteKey());
    }

    public function test_public_ids_are_generated_and_unique(): void
    {
        $this->assertSame(26, mb_strlen($this->alpha->public_id));
        $this->assertNotSame($this->alpha->public_id, $this->beta->public_id);
    }

    public function test_membership_rows_do_not_leak_between_workspaces(): void
    {
        $this->assertSame([$this->alphaOwner->getKey()], $this->alpha->memberships()->pluck('user_id')->all());
        $this->assertSame([$this->betaOwner->getKey()], $this->beta->memberships()->pluck('user_id')->all());

        $this->assertNull($this->beta->membershipFor($this->alphaOwner));
        $this->assertNull($this->alpha->roleFor($this->betaOwner));
    }

    public function test_membership_is_unique_per_workspace_and_user(): void
    {
        $this->expectException(QueryException::class);

        WorkspaceMembership::create([
            'workspace_id' => $this->alpha->getKey(),
            'user_id' => $this->alphaOwner->getKey(),
            'role' => WorkspaceRole::Auditor,
        ]);
    }

    public function test_the_same_person_can_hold_different_roles_in_different_workspaces(): void
    {
        WorkspaceMembership::create([
            'workspace_id' => $this->beta->getKey(),
            'user_id' => $this->alphaOwner->getKey(),
            'role' => WorkspaceRole::Auditor,
        ]);

        $this->assertSame(WorkspaceRole::Owner, $this->alpha->roleFor($this->alphaOwner));
        $this->assertSame(WorkspaceRole::Auditor, $this->beta->fresh()?->roleFor($this->alphaOwner));

        $this->assertTrue($this->alphaOwner->can(WorkspacePermission::Delete->value, $this->alpha));
        $this->assertFalse($this->alphaOwner->can(WorkspacePermission::Delete->value, $this->beta->fresh()));
    }

    public function test_a_service_credential_reaches_only_its_own_workspace(): void
    {
        $issued = app(ServiceCredentialIssuer::class)->issue(
            $this->alpha,
            'consumer production',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
        );

        $principal = new CurrentPrincipal;
        $principal->bind($issued->credential);

        // Workspace first, identifier second. Beta's public id is correct and the query
        // still finds nothing, so the caller cannot tell "forbidden" from "absent".
        $this->assertNull(
            Workspace::query()
                ->whereKey($principal->workspaceIdOrFail())
                ->where('public_id', $this->beta->public_id)
                ->first(),
        );

        $this->assertSame(
            'alpha',
            Workspace::query()
                ->whereKey($principal->workspaceIdOrFail())
                ->where('public_id', $this->alpha->public_id)
                ->first()?->slug,
        );
    }

    public function test_issuing_a_service_credential_confers_no_membership_and_no_role(): void
    {
        app(ServiceCredentialIssuer::class)->issue(
            $this->alpha,
            'consumer production',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
        );

        // An API caller is a principal, not a person. Issuing one creates no membership row,
        // so it can never widen anybody's abilities in either workspace.
        $this->assertSame([$this->alphaOwner->getKey()], $this->alpha->memberships()->pluck('user_id')->all());
        $this->assertSame([$this->betaOwner->getKey()], $this->beta->memberships()->pluck('user_id')->all());
    }

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return $user;
    }
}
