<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DocumentWorkspace;
use Tests\TestCase;

/**
 * The members page and invitation links over HTTP (issue #110).
 *
 * The rules themselves are `WorkspaceMembersTest`'s. This pins the boundary: who reaches the page
 * at all, what a refusal looks like on the wire, and that following an invitation link changes
 * nothing until the person signed in presses accept.
 */
final class MembersHttpTest extends TestCase
{
    use RefreshDatabase;

    private const JSON = ['Accept' => 'application/json'];

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['name' => 'Synthetic Workspace']);
        $this->owner = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Owner);
        $this->admin = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Admin);
        $this->sender = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Sender);
    }

    // ------------------------------------------------------------------------ the page

    public function test_an_owner_sees_the_page_and_its_state(): void
    {
        $this->actingAs($this->owner)
            ->get($this->membersUrl())
            ->assertOk()
            ->assertSee('Members of Synthetic Workspace')
            ->assertSee('id="members"', false);

        $this->actingAs($this->owner)
            ->getJson($this->membersUrl())
            ->assertOk()
            ->assertJsonCount(3, 'members')
            ->assertJsonPath('viewer.isOwner', true)
            ->assertJsonPath('roles.0.value', 'owner')
            ->assertJsonPath('roles.0.grantable', true);
    }

    public function test_an_administrator_is_not_offered_the_owner_role(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->membersUrl())
            ->assertOk()
            ->assertJsonPath('roles.0.grantable', false)
            ->assertJsonPath('roles.1.grantable', true);
    }

    public function test_a_sender_is_forbidden_and_an_outsider_finds_nothing(): void
    {
        $this->actingAs($this->sender)->getJson($this->membersUrl())->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($this->membersUrl())->assertNotFound();
    }

    public function test_a_guest_is_not_authenticated(): void
    {
        $this->getJson($this->membersUrl())->assertUnauthorized();
    }

    // ------------------------------------------------------------------------ actions

    public function test_an_invitation_link_is_returned_once_and_never_cached(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson($this->workspaceUrl('/invitations'), ['role' => 'sender'])
            ->assertCreated()
            ->assertJsonCount(1, 'state.invitations');

        $this->assertMatchesRegularExpression('#/invitations/[0-9a-f]{64}\z#', (string) $response->json('url'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_refusal_carries_the_services_code_and_status(): void
    {
        $this->actingAs($this->admin)
            ->postJson($this->workspaceUrl('/invitations'), ['role' => 'owner'])
            ->assertForbidden()
            ->assertJsonPath('code', 'owner_only');

        $this->actingAs($this->owner)
            ->patchJson($this->memberUrl($this->owner), ['role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'last_owner');

        $this->actingAs($this->admin)
            ->postJson($this->workspaceUrl('/invitations'), ['role' => 'emperor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_a_role_change_answers_with_the_stored_state(): void
    {
        $this->actingAs($this->admin)
            ->patchJson($this->memberUrl($this->sender), ['role' => 'auditor'])
            ->assertOk()
            ->assertJsonPath('members.2.role', 'auditor');

        $this->assertSame(WorkspaceRole::Auditor, $this->membershipOf($this->sender)->role);
    }

    public function test_a_member_of_another_workspace_is_not_found_by_public_id(): void
    {
        $elsewhere = Workspace::factory()->create();
        $stranger = DocumentWorkspace::memberOf($elsewhere, WorkspaceRole::Sender);
        $theirs = WorkspaceMembership::query()->where('user_id', $stranger->getKey())->sole();

        $this->actingAs($this->owner)
            ->deleteJson($this->workspaceUrl('/members/'.$theirs->public_id))
            ->assertNotFound();

        $this->assertTrue($theirs->fresh() instanceof WorkspaceMembership);
    }

    public function test_revoking_an_invitation_removes_it_from_the_open_list(): void
    {
        [$invitation] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Sender);

        $this->actingAs($this->admin)
            ->deleteJson($this->workspaceUrl('/invitations/'.$invitation->public_id))
            ->assertOk()
            ->assertJsonCount(0, 'invitations');
    }

    // ------------------------------------------------------------------------ following a link

    public function test_following_a_link_shows_it_and_changes_nothing_until_accepted(): void
    {
        [$invitation, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Auditor);
        $newcomer = User::factory()->create(['name' => 'Example Newcomer']);

        $this->actingAs($newcomer)
            ->get('/invitations/'.$token)
            ->assertOk()
            ->assertSee('Join Synthetic Workspace')
            ->assertSee('Example Newcomer')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertFalse(WorkspaceMembership::query()->where('user_id', $newcomer->getKey())->exists());
        $this->assertNull($invitation->fresh()?->redeemed_at);

        $this->actingAs($newcomer)
            ->post('/invitations/'.$token)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'You joined Synthetic Workspace as Auditor.');

        $this->assertSame(WorkspaceRole::Auditor, $this->membershipOf($newcomer)->role);

        $this->actingAs($newcomer)
            ->get('/invitations/'.$token)
            ->assertNotFound()
            ->assertSee('This invitation cannot be used');
    }

    public function test_a_guest_is_sent_to_sign_in_and_brought_back_to_the_link(): void
    {
        [, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Sender);

        $this->get('/invitations/'.$token)->assertRedirect(route('login'));
        $this->assertStringEndsWith('/invitations/'.$token, (string) session('url.intended'));
    }

    public function test_an_existing_member_is_told_why_and_the_link_stays_usable(): void
    {
        [$invitation, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Admin);

        $this->actingAs($this->sender)
            ->post('/invitations/'.$token)
            ->assertRedirect('/invitations/'.$token)
            ->assertSessionHasErrors('invitation');

        $this->assertTrue($invitation->fresh()?->isOpen());
        $this->assertSame(WorkspaceRole::Sender, $this->membershipOf($this->sender)->role);
    }

    public function test_the_dashboard_links_to_members_only_where_the_role_can_manage_them(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('membersUrl', false)->assertSee('members', false);

        $html = (string) $this->actingAs($this->sender)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('&quot;membersUrl&quot;:null', $html);
    }

    // ------------------------------------------------------------------------ helpers

    private function workspaceUrl(string $path): string
    {
        return '/workspaces/'.$this->workspace->public_id.$path;
    }

    private function membersUrl(): string
    {
        return $this->workspaceUrl('/members');
    }

    private function memberUrl(User $user): string
    {
        return $this->workspaceUrl('/members/'.$this->membershipOf($user)->public_id);
    }

    private function membershipOf(User $user): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('user_id', $user->getKey())
            ->sole();
    }
}
