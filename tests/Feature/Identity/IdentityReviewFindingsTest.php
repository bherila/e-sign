<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Audit\AuditRecorder;
use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Domain\Identity\Services\BootstrapOutcome;
use App\Domain\Identity\Services\BootstrapRefused;
use App\Domain\Identity\Services\OwnerBootstrapper;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * Reproductions for the findings an independent review raised against the
 * Identity module, each written to fail before its fix.
 */
final class IdentityReviewFindingsTest extends TestCase
{
    use RefreshDatabase;

    /** F4: a constrained eager load must not be mistaken for the whole relation. */
    public function test_membership_lookup_ignores_a_partially_eager_loaded_relation(): void
    {
        $workspace = Workspace::factory()->create();
        $sender = User::factory()->create();
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->getKey(), 'role' => WorkspaceRole::Owner]);
        $workspace->memberships()->create(['user_id' => $sender->getKey(), 'role' => WorkspaceRole::Sender]);

        // The shape a workspace index takes when it eager-loads owners to display
        // them. The relation is loaded but filtered, and nothing on the model can
        // tell that apart from "this workspace has one membership".
        $loaded = Workspace::query()
            ->whereKey($workspace->getKey())
            ->with(['memberships' => fn ($query) => $query->where('role', WorkspaceRole::Owner->value)])
            ->first();

        $this->assertNotNull($loaded);
        $this->assertSame(WorkspaceRole::Sender, $loaded->roleFor($sender));
        $this->assertSame(WorkspaceRole::Owner, $loaded->roleFor($owner));
    }

    /** F4: the fully eager-loaded case must still not hit the database. */
    public function test_membership_lookup_uses_a_complete_eager_load_without_querying(): void
    {
        $workspace = Workspace::factory()->create();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->getKey(), 'role' => WorkspaceRole::Sender]);

        $loaded = Workspace::query()->whereKey($workspace->getKey())->with('memberships')->first();
        $this->assertNotNull($loaded);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame(WorkspaceRole::Sender, $loaded->roleFor($member));
        $this->assertSame(0, $queries, 'a complete eager load must be reused, not re-queried');
    }

    /** F4: repeated ability checks on one instance must not re-query per call. */
    public function test_membership_lookup_is_memoized_per_user(): void
    {
        $workspace = Workspace::factory()->create();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->getKey(), 'role' => WorkspaceRole::Admin]);

        $fresh = Workspace::query()->whereKey($workspace->getKey())->first();
        $this->assertNotNull($fresh);
        $fresh->roleFor($member);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $fresh->roleFor($member);
        $fresh->roleFor($member);

        $this->assertSame(0, $queries, 'a repeated lookup for the same user must be memoized');
    }

    /** F5: the pivot must carry the same enum the membership model does. */
    public function test_the_members_pivot_casts_role_to_the_enum(): void
    {
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->getKey(), 'role' => WorkspaceRole::Owner]);

        $member = $workspace->members()->first();

        $this->assertNotNull($member);
        $this->assertInstanceOf(WorkspaceMembership::class, $member->pivot);
        $this->assertSame(WorkspaceRole::Owner, $member->pivot->role);
        // Calling ->can() on the pivot role is a fatal when it is a string,
        // so this line is the real regression guard.
        $this->assertTrue($member->pivot->role->can(WorkspacePermission::RotateCredentials));
    }

    /**
     * F7: the append-only guarantee the docblock claims, tested at its edges.
     *
     * The model hooks stop an application from rewriting history through the
     * model. They do not, and cannot, stop the query builder — which is what the
     * docblock has to say, so that nobody relies on the wrong boundary.
     */
    public function test_append_only_is_enforced_on_the_model_and_not_on_the_query_builder(): void
    {
        $event = AuditEvent::create([
            'actor_type' => 'console',
            'actor_label' => 'test',
            'action' => 'identity.probe',
        ]);

        $modelUpdateRefused = false;
        try {
            $event->update(['action' => 'tampered']);
        } catch (\RuntimeException) {
            $modelUpdateRefused = true;
        }
        $this->assertTrue($modelUpdateRefused, 'a model update must be refused');

        $modelDeleteRefused = false;
        try {
            $event->delete();
        } catch (\RuntimeException) {
            $modelDeleteRefused = true;
        }
        $this->assertTrue($modelDeleteRefused, 'a model delete must be refused');

        // The documented boundary: these succeed, and the docblock must not
        // imply otherwise.
        AuditEvent::query()->whereKey($event->getKey())->update(['action' => 'bypassed']);
        $this->assertSame('bypassed', (string) DB::table('esign_audit_events')
            ->where('id', $event->getKey())->value('action'));

        DB::table('esign_audit_events')->where('id', $event->getKey())->delete();
        $this->assertDatabaseCount('esign_audit_events', 0);
    }

    /**
     * F1: a database fault must not be reported as an operator refusal.
     *
     * The fault is injected at the service boundary rather than by breaking the
     * schema. An earlier version of this test dropped a table, which passes on
     * SQLite and fails on MySQL and MariaDB: DDL there implicitly commits, which
     * destroys the savepoint RefreshDatabase runs inside, so the test saw a
     * PDOException about a missing SAVEPOINT instead of the QueryException it
     * was looking for. The engine matrix caught that. What is under test is the
     * command's exception policy, and this exercises exactly that, on every
     * engine.
     */
    public function test_a_database_fault_is_not_reported_as_a_domain_refusal(): void
    {
        $user = User::factory()->create();

        $this->swap(OwnerBootstrapper::class, new class(app(AuditRecorder::class)) extends OwnerBootstrapper
        {
            public function bootstrapLocalUser(User $user, string $slug, ?string $name, AuditActor $actor): BootstrapOutcome
            {
                // A QueryException is a RuntimeException, so the old catch
                // treated every SQL failure as a refusal and printed the
                // statement and its bindings as the problem.
                throw new QueryException(
                    'mysql',
                    'insert into `workspace_memberships` (`user_id`) values (?)',
                    [$user->getKey()],
                    new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
                );
            }
        });

        $this->expectException(QueryException::class);

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])->run();
    }

    /** F1: a genuine domain refusal must still be caught and printed. */
    public function test_a_domain_refusal_is_still_reported_to_the_operator(): void
    {
        $user = User::factory()->create();

        $this->swap(OwnerBootstrapper::class, new class(app(AuditRecorder::class)) extends OwnerBootstrapper
        {
            public function bootstrapLocalUser(User $user, string $slug, ?string $name, AuditActor $actor): BootstrapOutcome
            {
                throw new BootstrapRefused("Something the operator can fix.\nDo this instead.");
            }
        });

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $user->getKey(),
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('Something the operator can fix.')
            ->expectsOutputToContain('Do this instead.')
            ->assertExitCode(1);
    }

    /** F2: SSO mode must refuse when the provider URL is not configured. */
    public function test_sso_mode_refuses_when_only_the_provider_url_is_unset(): void
    {
        // The operator set the client credentials and forgot the URL. The config
        // default ('https://bherila.net') meant the guard saw a value and let
        // the bootstrap through, silently bound to somebody else's provider.
        config()->set('bherila-auth.oauth_client.client_id', 'a-client-id');
        config()->set('bherila-auth.oauth_client.base_url', 'https://bherila.net');
        config()->set('bherila-auth.oauth_client.provider', 'bherila');
        config()->set('esign.oauth_provider', 'bherila');
        config()->set('esign.oauth_provider_url', '');

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => 'bherila',
            '--subject' => 'subject-123',
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('OAUTH_PROVIDER_URL')
            ->assertExitCode(1);

        $this->assertDatabaseCount('workspaces', 0);
    }

    /** F3: an issuer that no login can ever match must be refused. */
    public function test_sso_mode_refuses_an_issuer_that_is_not_the_configured_provider(): void
    {
        config()->set('bherila-auth.oauth_client.provider', 'acme-idp');
        config()->set('bherila-auth.oauth_client.client_id', 'a-client-id');
        config()->set('bherila-auth.oauth_client.base_url', 'https://idp.example.com');
        config()->set('esign.oauth_provider_url', 'https://idp.example.com');
        config()->set('esign.oauth_provider', 'acme-idp');

        // The login flow resolves a binding on the configured provider key, so a
        // binding stored under anything else is dead on arrival: the owner can
        // never sign in, and re-running with the right issuer leaves an orphan.
        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => 'https://idp.example.com',
            '--subject' => 'subject-123',
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('acme-idp')
            ->assertExitCode(1);

        $this->assertDatabaseCount('identity_bindings', 0);
        $this->assertDatabaseCount('workspaces', 0);
    }

    /** F3: the configured provider key must still be accepted. */
    public function test_sso_mode_accepts_the_configured_provider_key(): void
    {
        config()->set('bherila-auth.oauth_client.provider', 'acme-idp');
        config()->set('bherila-auth.oauth_client.client_id', 'a-client-id');
        config()->set('bherila-auth.oauth_client.base_url', 'https://idp.example.com');
        config()->set('esign.oauth_provider_url', 'https://idp.example.com');
        config()->set('esign.oauth_provider', 'acme-idp');

        $this->artisan('esign:bootstrap-owner', [
            '--issuer' => 'acme-idp',
            '--subject' => 'subject-123',
            '--workspace' => 'acme',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('identity_bindings', ['issuer' => 'acme-idp', 'subject' => 'subject-123']);
    }

    /** F8: joining a workspace that already has a different owner must be visible. */
    public function test_joining_a_workspace_that_already_has_another_owner_is_reported(): void
    {
        $workspace = Workspace::factory()->create(['slug' => 'acme']);
        $incumbent = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $incumbent->getKey(), 'role' => WorkspaceRole::Owner]);

        $newcomer = User::factory()->create();

        $this->artisan('esign:bootstrap-owner', [
            '--user' => (string) $newcomer->getKey(),
            '--workspace' => 'acme',
        ])
            ->expectsOutputToContain('already has')
            ->assertExitCode(0);
    }
}
