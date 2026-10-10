<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delegated access adapter against the package's normative semantics, contract version 3.
 *
 * The manager administers two workspaces. The target is a sender in one of them (editable), an
 * owner in the other (visible but not editable by an administrator) and a sender in a third the
 * manager cannot see. The removable subject is a sender and an auditor in the managed workspaces,
 * and a sender in the unseen one, and has signed in. A hidden person, matching the same searches,
 * belongs only to the unseen workspace. Every assertion checks the membership table directly.
 */
final class DelegatedAccessConformanceTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use RefreshDatabase;

    private const PROVIDER = 'example-provider';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bherila-auth.oauth_client.provider' => self::PROVIDER,
            'esign.oauth_provider' => self::PROVIDER,
            'bherila-auth.delegated_access.application' => 'e-sign',
            'bherila-auth.delegated_access.issuer' => 'https://identity.example.test',
            'bherila-auth.delegated_access.oauth_provider' => self::PROVIDER,
        ]);

        $first = Workspace::factory()->create(['name' => 'First Workspace']);
        $second = Workspace::factory()->create(['name' => 'Second Workspace']);
        $unseen = Workspace::factory()->create(['name' => 'Unseen Workspace']);

        $manager = $this->bound('manager-subject', 'Example Manager');
        $this->member($first, $manager, WorkspaceRole::Admin);
        $this->member($second, $manager, WorkspaceRole::Admin);

        // An owner a second owner backs, so that only the administrator's limits protect it.
        $this->member($second, $this->bound('co-owner-subject', 'Example Co-owner'), WorkspaceRole::Owner);

        $target = $this->bound('target-subject', 'Example Target');
        $this->member($first, $target, WorkspaceRole::Sender);
        $this->member($second, $target, WorkspaceRole::Owner);
        $this->member($unseen, $target, WorkspaceRole::Sender);

        $removable = $this->bound('removable-subject', 'Example Removable', lastSeen: now()->subDay());
        $this->member($first, $removable, WorkspaceRole::Sender);
        $this->member($second, $removable, WorkspaceRole::Auditor);
        $this->member($unseen, $removable, WorkspaceRole::Sender);

        // Matches the searches below, but only in a workspace the manager cannot see.
        $this->member($unseen, $this->bound('hidden-subject', 'Example Hidden Person'), WorkspaceRole::Sender);

        // Bound here and a member, but manages nothing.
        $this->member($first, $this->bound('stranger-subject', 'Example Stranger'), WorkspaceRole::Sender);
    }

    public function test_the_adapter_follows_the_normative_update_semantics(): void
    {
        $workspace = Workspace::query()->where('name', 'First Workspace')->value('public_id');

        $this->assertDelegatedActorRefusedEverywhere('stranger-subject', 'target-subject', (string) $workspace);
        $this->assertDelegatedProtectedMembershipsHold('manager-subject', 'target-subject');
        $this->assertDelegatedApplicationAdminFollowsAllowedEdits('manager-subject', 'target-subject');
        $this->assertDelegatedStaleRevisionRefused('manager-subject', 'target-subject');
        $this->assertDelegatedUnadvertisedRoleRefused('manager-subject', 'target-subject');
        $this->assertDelegatedUpdateKeepsUnseenMemberships('manager-subject', 'target-subject');
    }

    public function test_a_search_stays_in_the_managers_scope(): void
    {
        $this->assertDelegatedSearchStaysInScope('manager-subject', 'subjects', 'Example', 'Hidden Person');
        $this->assertDelegatedSearchStaysInScope('manager-subject', 'workspaces', 'Workspace', 'Unseen');
    }

    public function test_a_removal_is_the_whole_managed_projection_or_nothing(): void
    {
        $this->assertDelegatedRemoveRefusedWithoutPartialChange('manager-subject', 'target-subject');
        $this->assertDelegatedRemoveStripsOnlyTheManagedProjection('manager-subject', 'removable-subject');
    }

    public function test_metadata_is_well_formed(): void
    {
        $this->assertDelegatedMetadataIsWellFormed('manager-subject', 'removable-subject');
    }

    public function test_writes_replay_from_their_receipts_through_the_endpoint(): void
    {
        $this->assertDelegatedReceiptsReplayThroughTheEndpoint('manager-subject', 'target-subject');
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $user = IdentityBinding::query()->where('issuer', self::PROVIDER)->where('subject', $subject)->value('user_id');
        $workspaces = [];
        foreach (WorkspaceMembership::query()->where('user_id', $user)->with('workspace')->get() as $membership) {
            $workspaces[(string) $membership->workspace->public_id] = $membership->role->value;
        }

        // e-sign has no application-wide administrator.
        return ['application_admin' => false, 'workspaces' => $workspaces];
    }

    protected function delegatedAccessManager(): string
    {
        return 'manager-subject';
    }

    private function bound(string $subject, string $name, ?CarbonInterface $lastSeen = null): User
    {
        // Addresses that match none of the searches, so each search matches on the name alone.
        $user = User::factory()->create(['name' => $name, 'email' => $subject.'@directory.test']);
        IdentityBinding::create(['user_id' => $user->getKey(), 'issuer' => self::PROVIDER, 'subject' => $subject, 'last_seen_at' => $lastSeen]);

        return $user;
    }

    private function member(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
    }
}
