<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceInvitation;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\MembershipChangeRefused;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DocumentWorkspace;
use Tests\TestCase;

/**
 * Every rule about who may grant, change and remove a workspace role (issue #110).
 *
 * The members page and the delegated-access adapter both call `WorkspaceMembers`, so these rules
 * are asserted here, against the service, rather than once per surface.
 */
final class WorkspaceMembersTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $sender;

    private User $auditor;

    private WorkspaceMembers $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->owner = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Owner);
        $this->admin = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Admin);
        $this->sender = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Sender);
        $this->auditor = DocumentWorkspace::memberOf($this->workspace, WorkspaceRole::Auditor);
        $this->members = app(WorkspaceMembers::class);
    }

    // ------------------------------------------------------------------------ invitations

    public function test_an_invitation_stores_a_digest_of_a_token_it_returns_once(): void
    {
        CarbonImmutable::setTestNow('2026-09-14 12:00:00');

        [$invitation, $token] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Sender);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        $this->assertSame(hash('sha256', $token), $invitation->token_sha256);
        $this->assertSame(0, WorkspaceInvitation::query()->where('token_sha256', $token)->count(), 'The token itself was stored.');
        $this->assertSame('2026-09-21 12:00:00', $invitation->expires_at->format('Y-m-d H:i:s'));

        $event = AuditEvent::query()->where('action', 'identity.member_invited')->sole();
        $this->assertSame('sender', $event->payload['role'] ?? null);
        $this->assertStringNotContainsString($token, (string) json_encode($event->payload));
    }

    /** @return iterable<string, array{string, WorkspaceRole, string}> */
    public static function invitationAuthority(): iterable
    {
        yield 'an owner invites an owner' => ['owner', WorkspaceRole::Owner, ''];
        yield 'an administrator invites an administrator' => ['admin', WorkspaceRole::Admin, ''];
        yield 'an administrator may not invite an owner' => ['admin', WorkspaceRole::Owner, MembershipChangeRefused::OWNER_ONLY];
        yield 'a sender may not invite anyone' => ['sender', WorkspaceRole::Auditor, MembershipChangeRefused::NOT_PERMITTED];
        yield 'an auditor may not invite anyone' => ['auditor', WorkspaceRole::Auditor, MembershipChangeRefused::NOT_PERMITTED];
        yield 'a non-member may not invite anyone' => ['outsider', WorkspaceRole::Auditor, MembershipChangeRefused::NOT_PERMITTED];
    }

    #[DataProvider('invitationAuthority')]
    public function test_who_may_invite_whom(string $actor, WorkspaceRole $role, string $refusal): void
    {
        $this->assertOutcome($refusal, fn () => $this->members->invite($this->workspace, $this->actor($actor), $role));
        $this->assertSame($refusal === '' ? 1 : 0, WorkspaceInvitation::query()->count());
    }

    public function test_redeeming_joins_the_signed_in_person_in_the_invited_role_once(): void
    {
        [$invitation, $token] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Auditor);
        $newcomer = User::factory()->create();

        $membership = $this->members->redeem($token, $newcomer);

        $this->assertSame(WorkspaceRole::Auditor, $membership->role);
        $this->assertSame($newcomer->getKey(), $membership->user_id);
        $this->assertNotNull($membership->public_id);
        $this->assertSame($newcomer->getKey(), $invitation->fresh()?->redeemed_by);
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.member_joined')->count());

        // Single use, even for somebody else.
        $this->assertOutcome(MembershipChangeRefused::INVITATION_UNAVAILABLE, fn () => $this->members->redeem($token, User::factory()->create()));
    }

    /** @return iterable<string, array{Closure(WorkspaceInvitation, WorkspaceMembersTest): void}> */
    public static function unusableInvitations(): iterable
    {
        yield 'expired' => [static function (WorkspaceInvitation $invitation): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(WorkspaceMembers::INVITATION_LIFETIME_DAYS)->addSecond());
        }];
        yield 'revoked' => [static function (WorkspaceInvitation $invitation, WorkspaceMembersTest $test): void {
            $test->revoke($invitation);
        }];
        yield 'its workspace was deleted' => [static function (WorkspaceInvitation $invitation): void {
            $invitation->workspace?->delete();
        }];
    }

    /**
     * @param  Closure(WorkspaceInvitation, WorkspaceMembersTest): void  $spoil
     */
    #[DataProvider('unusableInvitations')]
    public function test_an_unusable_invitation_is_refused_and_grants_nothing(Closure $spoil): void
    {
        [$invitation, $token] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Sender);
        $spoil($invitation, $this);
        $newcomer = User::factory()->create();

        $this->assertOutcome(MembershipChangeRefused::INVITATION_UNAVAILABLE, fn () => $this->members->redeem($token, $newcomer));
        $this->assertFalse(WorkspaceMembership::query()->where('user_id', $newcomer->getKey())->exists());
    }

    public function test_a_malformed_token_is_refused_without_a_lookup(): void
    {
        $this->assertOutcome(MembershipChangeRefused::INVITATION_UNAVAILABLE, fn () => $this->members->redeem('not-a-token', $this->sender));
        $this->assertNull($this->members->openInvitationFor(str_repeat('A', 64)));
    }

    /**
     * A member who follows a link meant for somebody else neither uses it up nor changes their
     * own role through it.
     */
    public function test_an_existing_member_cannot_use_an_invitation_and_leaves_it_open(): void
    {
        [$invitation, $token] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Admin);

        $this->assertOutcome(MembershipChangeRefused::ALREADY_MEMBER, fn () => $this->members->redeem($token, $this->sender));

        $this->assertSame(WorkspaceRole::Sender, $this->workspace->memberships()->where('user_id', $this->sender->getKey())->sole()->role);
        $this->assertTrue($invitation->fresh()?->isOpen());
    }

    /**
     * Two invitations to one workspace, redeemed by one person at the same moment (#125 review).
     *
     * Each transaction sees no membership, so the loser's insert hits the unique index. That is the
     * documented refusal rather than a 500, and the losing invitation stays open. The race is forced
     * by inserting the winner's row at the instant the loser creates its own.
     */
    public function test_a_redemption_that_loses_a_race_is_refused_and_leaves_its_invitation_open(): void
    {
        [$invitation, $token] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Sender);
        $newcomer = User::factory()->create();

        WorkspaceMembership::creating(function (WorkspaceMembership $membership) use ($newcomer): void {
            if ($membership->user_id === $newcomer->getKey()) {
                DB::table('workspace_memberships')->insert([
                    'public_id' => (string) Str::ulid(),
                    'workspace_id' => $membership->workspace_id,
                    'user_id' => $newcomer->getKey(),
                    'role' => WorkspaceRole::Auditor->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->assertOutcome(MembershipChangeRefused::ALREADY_MEMBER, fn () => $this->members->redeem($token, $newcomer));
        $this->assertTrue($invitation->fresh()?->isOpen());
    }

    public function test_looking_an_invitation_up_changes_nothing(): void
    {
        [$invitation, $token] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Sender);

        $found = $this->members->openInvitationFor($token);

        $this->assertSame($invitation->getKey(), $found?->getKey());
        $this->assertNull($invitation->fresh()?->redeemed_at);
        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_revoking_is_audited_once_and_cannot_be_repeated(): void
    {
        [$invitation] = $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Sender);

        $this->members->revokeInvitation($this->workspace, $this->admin, $invitation);

        $this->assertNotNull($invitation->fresh()?->revoked_at);
        $this->assertOutcome(MembershipChangeRefused::INVITATION_UNAVAILABLE, fn () => $this->members->revokeInvitation($this->workspace, $this->owner, $invitation));
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.member_invitation_revoked')->count());
    }

    // ------------------------------------------------------------------------ roles

    /** @return iterable<string, array{string, string, WorkspaceRole, string}> */
    public static function roleChanges(): iterable
    {
        yield 'an administrator demotes a sender' => ['admin', 'sender', WorkspaceRole::Auditor, ''];
        yield 'an administrator promotes an auditor to administrator' => ['admin', 'auditor', WorkspaceRole::Admin, ''];
        yield 'an administrator may not make anyone an owner' => ['admin', 'sender', WorkspaceRole::Owner, MembershipChangeRefused::OWNER_ONLY];
        yield "an administrator may not change an owner's role" => ['admin', 'owner', WorkspaceRole::Admin, MembershipChangeRefused::OWNER_ONLY];
        yield 'an owner makes an administrator an owner' => ['owner', 'admin', WorkspaceRole::Owner, ''];
        yield 'the only owner may not step down' => ['owner', 'owner', WorkspaceRole::Admin, MembershipChangeRefused::LAST_OWNER];
        yield 'a sender may not change roles' => ['sender', 'auditor', WorkspaceRole::Sender, MembershipChangeRefused::NOT_PERMITTED];
    }

    #[DataProvider('roleChanges')]
    public function test_who_may_change_which_role(string $actor, string $target, WorkspaceRole $role, string $refusal): void
    {
        $membership = $this->membershipOf($this->actor($target));
        $before = $membership->role;

        $this->assertOutcome($refusal, fn () => $this->members->changeRole($this->workspace, $this->actor($actor), $membership, $role));

        $this->assertSame($refusal === '' ? $role : $before, $membership->fresh()?->role);
        $this->assertSame($refusal === '' ? 1 : 0, AuditEvent::query()->where('action', 'identity.member_role_changed')->count());
    }

    public function test_an_owner_can_step_down_once_there_is_another_owner(): void
    {
        $this->members->changeRole($this->workspace, $this->owner, $this->membershipOf($this->admin), WorkspaceRole::Owner);
        $this->members->changeRole($this->workspace, $this->owner, $this->membershipOf($this->owner), WorkspaceRole::Admin);

        $this->assertSame(WorkspaceRole::Admin, $this->membershipOf($this->owner)->role);
        $this->assertSame(1, $this->workspace->memberships()->where('role', WorkspaceRole::Owner->value)->count());
    }

    public function test_setting_the_role_a_member_already_has_writes_nothing(): void
    {
        $this->members->changeRole($this->workspace, $this->admin, $this->membershipOf($this->sender), WorkspaceRole::Sender);

        $this->assertSame(0, AuditEvent::query()->count());
    }

    /**
     * Authority is read fresh on every call, never from the workspace's per-instance membership
     * cache: an administrator demoted a moment ago must not keep acting on what it remembers.
     */
    public function test_an_actor_demoted_mid_request_loses_the_authority_at_once(): void
    {
        $this->assertSame(WorkspaceRole::Admin, $this->workspace->roleFor($this->admin));

        $this->membershipOf($this->admin)->forceFill(['role' => WorkspaceRole::Auditor])->save();

        $this->assertOutcome(MembershipChangeRefused::NOT_PERMITTED, fn () => $this->members->invite($this->workspace, $this->admin, WorkspaceRole::Sender));
    }

    // ------------------------------------------------------------------------ removal

    /** @return iterable<string, array{string, string, string}> */
    public static function removals(): iterable
    {
        yield 'an administrator removes a sender' => ['admin', 'sender', ''];
        yield 'an administrator removes themselves' => ['admin', 'admin', ''];
        yield 'an administrator may not remove an owner' => ['admin', 'owner', MembershipChangeRefused::OWNER_ONLY];
        yield 'the only owner may not remove themselves' => ['owner', 'owner', MembershipChangeRefused::LAST_OWNER];
        yield 'an auditor may not remove anyone' => ['auditor', 'sender', MembershipChangeRefused::NOT_PERMITTED];
    }

    #[DataProvider('removals')]
    public function test_who_may_remove_whom(string $actor, string $target, string $refusal): void
    {
        $membership = $this->membershipOf($this->actor($target));

        $this->assertOutcome($refusal, fn () => $this->members->remove($this->workspace, $this->actor($actor), $membership));

        $this->assertSame($refusal !== '', WorkspaceMembership::query()->whereKey($membership->getKey())->exists());
        $this->assertSame($refusal === '' ? 1 : 0, AuditEvent::query()->where('action', 'identity.member_removed')->count());
    }

    public function test_a_membership_in_another_workspace_cannot_be_reached(): void
    {
        $elsewhere = Workspace::factory()->create();
        $stranger = DocumentWorkspace::memberOf($elsewhere, WorkspaceRole::Sender);
        $theirs = WorkspaceMembership::query()->where('user_id', $stranger->getKey())->sole();

        $this->assertOutcome(MembershipChangeRefused::NOT_PERMITTED, fn () => $this->members->remove($this->workspace, $this->owner, $theirs));
        $this->assertOutcome(MembershipChangeRefused::NOT_PERMITTED, fn () => $this->members->changeRole($this->workspace, $this->owner, $theirs, WorkspaceRole::Auditor));
        $this->assertTrue($theirs->fresh() instanceof WorkspaceMembership);
    }

    public function test_members_are_listed_most_senior_first(): void
    {
        $roles = array_map(static fn (WorkspaceMembership $m): string => $m->role->value, $this->members->members($this->workspace));

        $this->assertSame(['owner', 'admin', 'sender', 'auditor'], $roles);
    }

    // ------------------------------------------------------------------------ helpers

    public function revoke(WorkspaceInvitation $invitation): void
    {
        $this->members->revokeInvitation($this->workspace, $this->owner, $invitation);
    }

    private function actor(string $name): User
    {
        return match ($name) {
            'owner' => $this->owner,
            'admin' => $this->admin,
            'sender' => $this->sender,
            'auditor' => $this->auditor,
            default => User::factory()->create(),
        };
    }

    private function membershipOf(User $user): WorkspaceMembership
    {
        return $this->workspace->memberships()->where('user_id', $user->getKey())->sole();
    }

    /**
     * @param  string  $refusal  The expected refusal code, or '' for success.
     */
    private function assertOutcome(string $refusal, Closure $action): void
    {
        try {
            $action();
        } catch (MembershipChangeRefused $refused) {
            $this->assertSame($refusal, $refused->reason, 'Refused for a different reason: '.$refused->getMessage());

            return;
        }

        $this->assertSame('', $refusal, 'Expected the change to be refused with '.$refusal.'.');
    }
}
