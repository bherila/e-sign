<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Http\Controllers\Members\InvitationRedemptionController;
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

    /**
     * A link is rooted at the configured origin, never the request's (#125 review).
     *
     * Without a trusted-hosts list a request can name any Host, and a link built from it would hand
     * a live token to that origin the moment an administrator copied and followed it.
     */
    public function test_an_invitation_link_is_rooted_at_the_configured_origin_not_the_request_host(): void
    {
        config(['app.url' => 'https://esign.example.test']);

        $url = (string) $this->actingAs($this->admin)
            ->withHeader('Host', 'attacker.example.test')
            ->postJson($this->workspaceUrl('/invitations'), ['role' => 'sender'])
            ->assertCreated()
            ->json('url');

        $this->assertMatchesRegularExpression('#\Ahttps://esign\.example\.test/invitations/[0-9a-f]{64}\z#', $url);
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

    /**
     * The token never reaches the session store (#125 review).
     *
     * Following the link runs without a session, moves the token into an encrypted cookie and
     * redirects to a URL with no token in it. Remembering the link as `url.intended`, or as the
     * previous URL, would have written a live credential into a plaintext sessions table.
     */
    public function test_following_a_link_moves_the_token_into_a_cookie_and_never_into_the_session(): void
    {
        [, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Auditor);

        $response = $this->get('/invitations/'.$token)
            ->assertRedirect(route('invitations.accept'))
            ->assertCookie(InvitationRedemptionController::COOKIE, $token)
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->getCookie((string) config('session.cookie'), false), 'The link started a session.');
        $this->assertStringNotContainsString($token, (string) json_encode(session()->all()));
    }

    public function test_accepting_joins_the_signed_in_person_and_clears_the_cookie(): void
    {
        [$invitation, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Auditor);
        $newcomer = User::factory()->create(['name' => 'Example Newcomer']);

        $this->actingAs($newcomer)
            ->withCookie(InvitationRedemptionController::COOKIE, $token)
            ->get('/invitations/accept')
            ->assertOk()
            ->assertSee('Join Synthetic Workspace')
            ->assertSee('Example Newcomer')
            ->assertDontSee($token);

        // Showing the invitation changed nothing.
        $this->assertFalse(WorkspaceMembership::query()->where('user_id', $newcomer->getKey())->exists());
        $this->assertNull($invitation->fresh()?->redeemed_at);

        $this->actingAs($newcomer)
            ->withCookie(InvitationRedemptionController::COOKIE, $token)
            ->post('/invitations/accept', ['invitation' => $invitation->public_id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'You joined Synthetic Workspace as Auditor.')
            ->assertCookieExpired(InvitationRedemptionController::COOKIE);

        $this->assertSame(WorkspaceRole::Auditor, $this->membershipOf($newcomer)->role);

        $this->actingAs($newcomer)
            ->withCookie(InvitationRedemptionController::COOKIE, $token)
            ->get('/invitations/accept')
            ->assertNotFound()
            ->assertSee('This invitation cannot be used');
    }

    /**
     * Accepting binds to the invitation the page showed (#125 review).
     *
     * A second link opened in another tab replaces the cookie. The page still showing the first must
     * not accept the second, which can name another workspace or a different role.
     */
    public function test_accepting_a_page_that_shows_another_invitation_is_refused(): void
    {
        [$shown] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Auditor);
        [$replacing, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Admin);
        $newcomer = User::factory()->create();

        $this->actingAs($newcomer)
            ->withCookie(InvitationRedemptionController::COOKIE, $token)
            ->post('/invitations/accept', ['invitation' => $shown->public_id])
            ->assertRedirect(route('invitations.accept'))
            ->assertSessionHasErrors('invitation');

        $this->assertFalse(WorkspaceMembership::query()->where('user_id', $newcomer->getKey())->exists());
        $this->assertTrue($replacing->fresh()?->isOpen());
    }

    public function test_a_guest_is_brought_back_to_a_url_without_the_token(): void
    {
        [, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Sender);

        $this->withCookie(InvitationRedemptionController::COOKIE, $token)
            ->get('/invitations/accept')
            ->assertRedirect(route('login'));

        $this->assertStringEndsWith('/invitations/accept', (string) session('url.intended'));
        $this->assertStringNotContainsString($token, (string) json_encode(session()->all()));
    }

    public function test_without_the_cookie_there_is_nothing_to_accept(): void
    {
        $this->actingAs($this->sender)->get('/invitations/accept')->assertNotFound()->assertSee('This invitation cannot be used');
        $this->actingAs($this->sender)->post('/invitations/accept')->assertRedirect(route('invitations.accept'));
    }

    public function test_an_existing_member_is_told_why_and_the_link_stays_usable(): void
    {
        [$invitation, $token] = app(WorkspaceMembers::class)->invite($this->workspace, $this->owner, WorkspaceRole::Admin);

        $this->actingAs($this->sender)
            ->withCookie(InvitationRedemptionController::COOKIE, $token)
            ->post('/invitations/accept', ['invitation' => $invitation->public_id])
            ->assertRedirect(route('invitations.accept'))
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
