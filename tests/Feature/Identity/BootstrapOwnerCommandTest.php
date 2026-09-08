<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `esign:bootstrap-owner` — the only path to owner authority in the application.
 *
 * Covers both modes, idempotency, and every refusal. The refusals matter as much as the
 * happy paths: an operator who mistypes must get a message that says what to do, and a
 * missing configuration must stop the run rather than quietly provisioning by email.
 */
class BootstrapOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'example-provider';

    private const SUBJECT = 'a4e1c0de-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        // A registered OAuth client, as the runbook requires before SSO provisioning.
        config([
            'bherila-auth.oauth_client.provider' => self::ISSUER,
            'bherila-auth.oauth_client.base_url' => 'https://identity.example.test',
            'bherila-auth.oauth_client.client_id' => 'client-id-for-tests',
            // Mirrors of the raw environment. The package keys above carry
            // defaults, so these are what the command reads to decide whether a
            // provider was actually configured.
            'esign.oauth_provider' => self::ISSUER,
            'esign.oauth_provider_url' => 'https://identity.example.test',
        ]);
    }

    // ---------------------------------------------------------------- SSO mode

    public function test_sso_mode_creates_the_workspace_user_binding_and_owner_membership(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme-legal',
            '--name' => 'Acme Legal',
        ])->assertSuccessful();

        $workspace = Workspace::where('slug', 'acme-legal')->firstOrFail();
        $this->assertSame('Acme Legal', $workspace->name);
        $this->assertSame(26, mb_strlen($workspace->public_id));

        $binding = IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail();
        $this->assertNull($binding->last_seen_at, 'Provisioning must not pretend the owner has signed in.');

        $membership = $workspace->membershipFor($binding->user);
        $this->assertNotNull($membership);
        $this->assertSame(WorkspaceRole::Owner, $membership->role);

        $this->assertTrue($binding->user->can(WorkspacePermission::RotateCredentials->value, $workspace));
    }

    public function test_the_placeholder_user_row_is_unroutable_and_carries_no_real_identity(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $user = IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail()->user;

        // RFC 2606 reserves .invalid, so this address can never be delivered to and can
        // never be mistaken for the owner's real one. The real name and email arrive from
        // the provider at first sign-in.
        $this->assertStringEndsWith('@invalid', $user->email);
        $this->assertStringContainsString('Pending owner', $user->name);
    }

    public function test_the_workspace_name_defaults_to_the_slug_when_no_name_is_given(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme-legal',
        ])->assertSuccessful();

        $this->assertSame('Acme Legal', Workspace::where('slug', 'acme-legal')->firstOrFail()->name);
    }

    public function test_a_rerun_reports_the_existing_state_and_changes_nothing(): void
    {
        $arguments = [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ];

        $this->artisan('esign:bootstrap-owner', $arguments)->assertSuccessful();

        $before = $this->snapshot();

        $this->artisan('esign:bootstrap-owner', $arguments)
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, AuditEvent::count(), 'A no-op rerun must not append an audit event.');
    }

    public function test_a_rerun_after_the_owner_has_signed_in_does_not_reset_last_seen_at(): void
    {
        $arguments = [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ];

        $this->artisan('esign:bootstrap-owner', $arguments)->assertSuccessful();

        $binding = IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail();
        $binding->forceFill(['last_seen_at' => now()->subDay()])->save();
        $signedInAt = $binding->fresh()->last_seen_at;

        $this->artisan('esign:bootstrap-owner', $arguments)->assertSuccessful();

        $this->assertEquals($signedInAt, $binding->fresh()->last_seen_at);
    }

    public function test_sso_mode_never_matches_an_existing_user_by_email(): void
    {
        // The person already has a local account. Provisioning by (issuer, subject) must
        // not silently adopt it: the binding is the identity, the address is contact data.
        $existing = User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $binding = IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail();

        $this->assertNotSame($existing->getKey(), $binding->user_id);
        $this->assertSame(0, WorkspaceMembership::where('user_id', $existing->getKey())->count());
    }

    public function test_a_second_subject_in_the_same_workspace_gets_its_own_binding_and_membership(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => 'second-subject',
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $this->assertSame(1, Workspace::count());
        $this->assertSame(2, IdentityBinding::count());
        $this->assertSame(2, WorkspaceMembership::where('role', WorkspaceRole::Owner->value)->count());
        $this->assertSame(2, AuditEvent::count());
    }

    public function test_the_same_subject_under_a_different_issuer_is_a_different_person(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        // The second issuer is reached by repointing the deployment, which is
        // the only way a second issuer can legitimately arise: --issuer must
        // name the provider this installation authenticates against, because a
        // binding under any other issuer can never be matched at sign-in.
        config([
            'bherila-auth.oauth_client.provider' => 'other-provider',
            'esign.oauth_provider' => 'other-provider',
        ]);

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => 'other-provider',
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $this->assertSame(2, IdentityBinding::count());
        $this->assertSame(2, User::count());
    }

    // ------------------------------------------------------- standalone mode

    public function test_standalone_mode_provisions_an_existing_user_by_id(): void
    {
        $user = User::factory()->create();

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $workspace = Workspace::where('slug', 'acme')->firstOrFail();

        $this->assertSame(WorkspaceRole::Owner, $workspace->roleFor($user));
        $this->assertSame(0, IdentityBinding::count(), 'Standalone mode creates no identity binding.');
        $this->assertSame(1, AuditEvent::where('action', 'identity.owner_bootstrapped')->count());
    }

    public function test_standalone_mode_provisions_an_existing_user_by_email(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);

        $this->artisan('esign:bootstrap-owner', [
            '--user' => 'admin@example.test',
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $this->assertSame(WorkspaceRole::Owner, Workspace::where('slug', 'acme')->firstOrFail()->roleFor($user));
    }

    public function test_standalone_mode_does_not_require_the_oauth_client(): void
    {
        // An OSS installation must not need any hosted identity endpoint.
        config([
            'bherila-auth.oauth_client.base_url' => '',
            'bherila-auth.oauth_client.client_id' => null,
            'esign.oauth_provider' => '',
            'esign.oauth_provider_url' => '',
        ]);

        $user = User::factory()->create();

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])->assertSuccessful();
    }

    public function test_standalone_mode_refuses_an_email_with_no_local_user_and_creates_nothing(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--user' => 'nobody@example.test',
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('No local user with email nobody@example.test exists.')
            ->expectsOutputToContain('never creates a user from an email address')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_standalone_mode_refuses_an_unknown_user_id(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--user' => '4242',
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('No local user with id 4242 exists.')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
    }

    public function test_standalone_mode_refuses_a_value_that_is_neither_an_id_nor_an_email(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--user' => 'dana',
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('neither a numeric user id nor an email address')
            ->assertFailed();
    }

    // ------------------------------------------------------------- refusals

    public function test_it_refuses_without_a_workspace(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
        ])
            ->expectsOutputToContain('--workspace is required.')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
    }

    public function test_it_refuses_an_invalid_workspace_slug(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'Acme Legal!',
        ])
            ->expectsOutputToContain('is not a valid workspace slug')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
    }

    public function test_it_refuses_when_no_identity_is_given(): void
    {
        $this->artisan('esign:bootstrap-owner', ['--workspace' => 'acme'])
            ->expectsOutputToContain('No owner identity was given.')
            ->expectsOutputToContain('never picks an owner for you')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, WorkspaceMembership::count());
    }

    public function test_it_refuses_when_both_modes_are_requested(): void
    {
        $user = User::factory()->create();

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('Choose one mode')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
    }

    public function test_it_refuses_an_issuer_without_a_subject(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('--subject is missing')
            ->assertFailed();
    }

    public function test_it_refuses_a_subject_without_an_issuer(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('--issuer is missing')
            ->assertFailed();
    }

    public function test_it_refuses_sso_mode_when_the_provider_url_is_unset(): void
    {
        config(['esign.oauth_provider_url' => '']);

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('OAUTH_PROVIDER_URL')
            ->expectsOutputToContain('docs/operations/bootstrap.md')
            ->assertFailed();

        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, IdentityBinding::count());
        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_sso_mode_when_the_client_id_is_unset(): void
    {
        config(['bherila-auth.oauth_client.client_id' => null]);

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('OAUTH_CLIENT_ID')
            ->assertFailed();
    }

    public function test_it_lists_every_missing_oauth_setting_at_once(): void
    {
        config([
            'esign.oauth_provider_url' => null,
            'bherila-auth.oauth_client.client_id' => '  ',
        ]);

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('OAUTH_PROVIDER_URL, OAUTH_CLIENT_ID')
            ->assertFailed();
    }

    public function test_it_refuses_when_a_revoked_bindings_user_row_is_still_present(): void
    {
        // Revoking login means deleting the identity binding, which leaves the placeholder
        // user row behind. Re-provisioning the same tuple must not adopt that row by its
        // address, and must not crash on the unique-email constraint either.
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        IdentityBinding::query()->forIssuerSubject(self::ISSUER, self::SUBJECT)->firstOrFail()->delete();

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('its identity binding was removed')
            ->expectsOutputToContain('never adopts a user row it found by address')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertSame(0, IdentityBinding::count());
    }

    public function test_it_refuses_to_reuse_a_soft_deleted_workspace(): void
    {
        Workspace::factory()->create(['slug' => 'acme'])->delete();

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('is deleted')
            ->assertFailed();

        $this->assertSame(0, IdentityBinding::count());
    }

    // ------------------------------------------------- existing state handling

    public function test_it_reuses_an_existing_workspace_and_never_renames_it(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme Holdings']);

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
            '--name' => 'Something Else',
        ])
            ->expectsOutputToContain('was not applied')
            ->assertSuccessful();

        $this->assertSame(1, Workspace::count());
        $this->assertSame('Acme Holdings', $workspace->fresh()->name);
    }

    public function test_it_promotes_an_existing_lesser_membership_to_owner(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'acme']);
        $user = User::factory()->create();
        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => WorkspaceRole::Sender,
        ]);

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('from sender to owner')
            ->assertSuccessful();

        $this->assertSame(WorkspaceRole::Owner, $workspace->fresh()->roleFor($user));
        $this->assertSame(1, WorkspaceMembership::count());
    }

    public function test_the_audit_event_records_the_console_actor_and_the_workspace(): void
    {
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => self::ISSUER,
            '--subject' => self::SUBJECT,
            '--workspace' => 'acme',
        ])->assertSuccessful();

        $event = AuditEvent::where('action', 'identity.owner_bootstrapped')->sole();
        $workspace = Workspace::where('slug', 'acme')->firstOrFail();

        $this->assertSame('cli', $event->actor_type);
        $this->assertSame('esign:bootstrap-owner', $event->actor_label);
        $this->assertSame(Workspace::class, $event->subject_type);
        $this->assertSame((string) $workspace->getKey(), $event->subject_id);
        $this->assertSame('sso', $event->payload['mode']);
        $this->assertSame(self::ISSUER, $event->payload['issuer']);
        $this->assertSame(self::SUBJECT, $event->payload['subject']);
        $this->assertSame('owner', $event->payload['role']);
        $this->assertSame('acme', $event->payload['workspace_slug']);
        $this->assertNotEmpty($event->payload['changes']);
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        return [
            'users' => User::count(),
            'workspaces' => Workspace::count(),
            'memberships' => WorkspaceMembership::count(),
            'bindings' => IdentityBinding::count(),
            'audit' => AuditEvent::count(),
        ];
    }
}
