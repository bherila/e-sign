<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessSettings;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\DelegatedAccess\NonceStore;
use BWH\Auth\OAuth\PendingAccount;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Tests\TestCase;

/**
 * POST /application-access, driven the way the identity provider drives it (issue #111).
 *
 * Every request carries a real RS256 actor assertion bound to its exact body, and every answer is
 * checked against delegated access contract version 3 from `bherila/auth-laravel`, the same
 * validator the provider applies. The membership rules themselves are `WorkspaceMembersTest`'s;
 * this pins what the provider can see and change, and what it cannot.
 */
final class DelegatedAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://identity.example.test';

    private const ENDPOINT = 'https://esign.example.test/application-access';

    private const APPLICATION = 'e-sign';

    private const PROVIDER = 'example-provider';

    private string $privateKey = '';

    private string $keyPath = '';

    private User $actor;

    private User $target;

    private Workspace $owned;

    private Workspace $administered;

    private Workspace $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $this->keyPath = (string) tempnam(sys_get_temp_dir(), 'esign-delegated-public-');
        file_put_contents($this->keyPath, openssl_pkey_get_details($key)['key']);

        config([
            'bherila-auth.oauth_client.provider' => self::PROVIDER,
            // The assertion issuer must be the sign-in provider.
            'bherila-auth.oauth_client.base_url' => self::ISSUER,
            'esign.oauth_provider' => self::PROVIDER,
            'bherila-auth.delegated_access' => [
                'enabled' => true,
                'writes_enabled' => true,
                'issuer' => self::ISSUER,
                'endpoint' => self::ENDPOINT,
                'application' => self::APPLICATION,
                'public_keys' => 'integration-v1|'.$this->keyPath,
                'oauth_provider' => self::PROVIDER,
            ],
        ]);

        $this->app->instance(NonceStore::class, new class implements NonceStore
        {
            /** @var array<string, true> */
            private array $seen = [];

            public function consume(string $key, int $seconds): bool
            {
                if (isset($this->seen[$key])) {
                    return false;
                }

                return $this->seen[$key] = true;
            }
        });

        $this->owned = Workspace::factory()->create(['name' => 'Owned Workspace']);
        $this->administered = Workspace::factory()->create(['name' => 'Administered Workspace']);
        $this->elsewhere = Workspace::factory()->create(['name' => 'Elsewhere Workspace']);

        $this->actor = $this->bound('actor-subject', 'Example Actor');
        $this->member($this->owned, $this->actor, WorkspaceRole::Owner);
        $this->member($this->administered, $this->actor, WorkspaceRole::Admin);

        $this->target = $this->bound('target-subject', 'Example Target');
        $this->member($this->owned, $this->target, WorkspaceRole::Sender);
        $this->member($this->elsewhere, $this->target, WorkspaceRole::Admin);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);

        parent::tearDown();
    }

    // ------------------------------------------------------------------------ the boundary

    public function test_the_route_is_absent_until_enabled(): void
    {
        config(['bherila-auth.delegated_access.enabled' => false]);

        $this->send(['operation' => 'capabilities'])->assertNotFound();
    }

    public function test_a_request_without_a_valid_assertion_is_refused_and_a_replay_is_refused(): void
    {
        $body = $this->body(['operation' => 'capabilities']);

        $this->call('POST', '/application-access', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(401)->assertJsonPath('error', 'invalid_actor_assertion');

        // Signed over a different body.
        $this->send(['operation' => 'capabilities'], token: $this->assertion('actor-subject', $this->body(['operation' => 'subjects'])))
            ->assertStatus(401);

        $token = $this->assertion('actor-subject', $body);
        $this->send(['operation' => 'capabilities'], token: $token)->assertOk();
        $this->send(['operation' => 'capabilities'], token: $token)->assertStatus(401)->assertJsonPath('error', 'replayed_actor_assertion');
    }

    public function test_an_actor_without_a_binding_or_without_a_managed_workspace_is_not_authorized(): void
    {
        $this->send(['operation' => 'capabilities'], subject: 'nobody-subject')->assertForbidden();

        // A provider administrator who is only a sender here gets nothing from being one there.
        $sender = $this->bound('sender-subject', 'Example Sender');
        $this->member($this->owned, $sender, WorkspaceRole::Sender);

        $this->send(['operation' => 'capabilities'], subject: 'sender-subject')->assertForbidden();
    }

    public function test_a_v1_or_v2_request_is_refused(): void
    {
        foreach ([1, 2] as $version) {
            $body = (string) json_encode(['contract_version' => $version, 'application' => self::APPLICATION, 'operation' => 'capabilities']);

            $this->send([], token: $this->assertion('actor-subject', $body), body: $body)->assertStatus(422);
        }
    }

    // ------------------------------------------------------------------------ reading

    public function test_capabilities_advertise_the_four_roles_and_provisioning(): void
    {
        $response = $this->validated($this->send(['operation' => 'capabilities']), 'capabilities');

        $this->assertSame(['owner', 'admin', 'sender', 'auditor'], array_column($response['controls']['workspace_roles'], 'id'));
        $this->assertFalse($response['controls']['application_admin']);
        $this->assertTrue($response['controls']['provisioning']);
    }

    public function test_only_workspaces_the_actor_manages_are_listed(): void
    {
        $response = $this->validated($this->send(['operation' => 'workspaces']), 'workspaces');

        $this->assertSame(
            [$this->owned->public_id, $this->administered->public_id],
            array_column($response['workspaces'], 'id'),
        );
    }

    public function test_subjects_are_people_bound_here_who_belong_to_a_managed_workspace(): void
    {
        $onlyElsewhere = $this->bound('elsewhere-subject', 'Example Elsewhere');
        $this->member($this->elsewhere, $onlyElsewhere, WorkspaceRole::Sender);

        $otherIssuer = User::factory()->create(['name' => 'Example Other Issuer']);
        IdentityBinding::create(['user_id' => $otherIssuer->getKey(), 'issuer' => 'another-provider', 'subject' => 'other-subject']);
        $this->member($this->owned, $otherIssuer, WorkspaceRole::Auditor);

        $response = $this->validated($this->send(['operation' => 'subjects']), 'subjects');
        $subjects = array_column($response['subjects'], 'subject');

        $this->assertContains('target-subject', $subjects);
        $this->assertNotContains('elsewhere-subject', $subjects);
        $this->assertNotContains('other-subject', $subjects);
    }

    public function test_a_read_shows_only_memberships_in_managed_workspaces(): void
    {
        $response = $this->validated($this->send(['operation' => 'read', 'subject' => 'target-subject']), 'read', 'target-subject');

        $this->assertTrue($response['provisioned']);
        $this->assertSame([['id' => $this->owned->public_id, 'role' => 'sender', 'editable' => true]], $response['access']['workspaces']);
        $this->assertFalse($response['allowed_edits']['provision']);
    }

    public function test_an_owner_membership_is_not_editable_by_an_administrator(): void
    {
        $this->member($this->administered, $this->target, WorkspaceRole::Owner);

        $response = $this->validated($this->send(['operation' => 'read', 'subject' => 'target-subject']), 'read', 'target-subject');
        $byId = array_column($response['access']['workspaces'], null, 'id');

        $this->assertFalse($byId[$this->administered->public_id]['editable']);
        $this->assertTrue($byId[$this->owned->public_id]['editable']);
    }

    public function test_an_unknown_subject_is_unprovisioned_and_may_be_provisioned(): void
    {
        $response = $this->validated($this->send(['operation' => 'read', 'subject' => 'new-subject']), 'read', 'new-subject');

        $this->assertFalse($response['provisioned']);
        $this->assertTrue($response['allowed_edits']['provision']);
    }

    /** Cursors carry the last id shown, so a row removed between pages skips nothing (#127 review). */
    public function test_a_subject_removed_between_pages_does_not_make_the_next_page_skip_one(): void
    {
        $later = $this->bound('later-subject', 'Example Later');
        $this->member($this->owned, $later, WorkspaceRole::Auditor);

        $first = $this->validated($this->send(['operation' => 'subjects', 'limit' => 2]), 'subjects');
        $this->assertSame(['actor-subject', 'target-subject'], array_column($first['subjects'], 'subject'));

        WorkspaceMembership::query()->where('workspace_id', $this->owned->getKey())->where('user_id', $this->target->getKey())->delete();

        $next = $this->validated($this->send(['operation' => 'subjects', 'limit' => 2, 'cursor' => $first['next_cursor']]), 'subjects');
        $this->assertSame(['later-subject'], array_column($next['subjects'], 'subject'));
        $this->assertNull($next['next_cursor']);
    }

    /** A search narrows each actor's own listing: what one manager finds, another may not. */
    public function test_a_search_stays_within_the_workspaces_each_actor_manages(): void
    {
        $hidden = $this->bound('hidden-subject', 'Example Hidden');
        $this->member($this->elsewhere, $hidden, WorkspaceRole::Sender);
        $other = $this->bound('other-manager-subject', 'Other Manager');
        $this->member($this->elsewhere, $other, WorkspaceRole::Owner);
        foreach (['actor' => $this->actor, 'target' => $this->target, 'hidden' => $hidden, 'other' => $other] as $who => $user) {
            $user->forceFill(['email' => "{$who}.person@directory.test"])->save();
        }

        $search = fn (string $operation, string $query, string $as = 'actor-subject'): array => array_column(
            $this->validated($this->send(['operation' => $operation, 'query' => $query], subject: $as), $operation)[$operation],
            $operation === 'subjects' ? 'subject' : 'id',
        );

        // Case-insensitive, on the name and on the stored email.
        $this->assertSame(['target-subject'], $search('subjects', 'eXaMpLe TaRgEt'));
        $this->assertSame(['target-subject'], $search('subjects', 'TARGET.PERSON@'));

        // Each manager finds only people in a workspace it manages, the target in both.
        $this->assertSame(['actor-subject', 'target-subject'], $search('subjects', 'example'));
        $this->assertSame(['actor-subject', 'target-subject'], $search('subjects', 'DIRECTORY.test'));
        $this->assertSame([], $search('subjects', 'hidden'));
        $this->assertSame(['target-subject', 'hidden-subject'], $search('subjects', 'example', 'other-manager-subject'));

        $this->assertSame([$this->owned->public_id, $this->administered->public_id], $search('workspaces', 'WORKSPACE'));
        $this->assertSame([], $search('workspaces', 'elsewhere'));
        $this->assertSame([$this->elsewhere->public_id], $search('workspaces', 'elsewhere', 'other-manager-subject'));

        // LIKE wildcards in a search are literal characters.
        $this->assertSame([], $search('subjects', '%%'));
        $this->assertSame([], $search('subjects', '__'));

        // A cursor belongs to its search: another search cannot continue it.
        $first = $this->validated($this->send(['operation' => 'subjects', 'query' => 'example', 'limit' => 1]), 'subjects');
        $this->assertSame(['actor-subject'], array_column($first['subjects'], 'subject'));
        $this->send(['operation' => 'subjects', 'query' => 'target', 'limit' => 1, 'cursor' => $first['next_cursor']])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_cursor');

        $next = $this->validated($this->send(['operation' => 'subjects', 'query' => 'example', 'limit' => 1, 'cursor' => $first['next_cursor']]), 'subjects');
        $this->assertSame(['target-subject'], array_column($next['subjects'], 'subject'));
        $this->assertNull($next['next_cursor']);
    }

    public function test_a_declared_oversize_body_is_refused_before_it_is_read(): void
    {
        $body = $this->body(['operation' => 'capabilities']);

        $this->call('POST', '/application-access', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_LENGTH' => (string) (DelegatedContract::MAX_REQUEST_BYTES + 1),
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->assertion('actor-subject', $body),
        ], $body)->assertStatus(422);
    }

    // ------------------------------------------------------------------------ changing

    public function test_an_update_changes_adds_and_removes_memberships_through_the_members_service(): void
    {
        $revision = $this->revisionOf('target-subject');
        $operationId = DelegatedContract::operationId();

        $response = $this->validated($this->send([
            'operation' => 'update',
            'subject' => 'target-subject',
            'expected_revision' => $revision,
            'access' => ['application_admin' => false, 'workspaces' => [['id' => $this->administered->public_id, 'role' => 'auditor']]],
            'operation_id' => $operationId,
        ]), 'update', 'target-subject');

        $this->assertSame([['id' => $this->administered->public_id, 'role' => 'auditor', 'editable' => true]], $response['access']['workspaces']);
        $this->assertNotSame($revision, $response['revision']);

        $this->assertFalse(WorkspaceMembership::query()->where('workspace_id', $this->owned->getKey())->where('user_id', $this->target->getKey())->exists());
        // A membership the actor cannot see is untouched.
        $this->assertTrue(WorkspaceMembership::query()->where('workspace_id', $this->elsewhere->getKey())->where('user_id', $this->target->getKey())->exists());

        // Each event names the surface, the provider's request (one per HTTP request) and the
        // operation (one per user action), so the two trails can be lined up.
        $events = AuditEvent::query()->whereIn('action', ['identity.member_granted', 'identity.member_removed'])->get();
        $this->assertCount(2, $events);
        $this->assertSame([['delegated_access', $operationId]], $events
            ->map(static fn (AuditEvent $event): array => [$event->payload['via'] ?? null, $event->payload['operation_id'] ?? null])->unique()->values()->all());
        $this->assertCount(1, $events->map(static fn (AuditEvent $event): mixed => $event->payload['request_id'] ?? null)->unique());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $events->first()?->payload['request_id']);
    }

    public function test_a_stale_revision_is_a_conflict_and_changes_nothing(): void
    {
        $this->send([
            'operation' => 'update',
            'subject' => 'target-subject',
            'expected_revision' => str_repeat('0', 64),
            'access' => ['application_admin' => false, 'workspaces' => []],
        ])->assertStatus(409)->assertJsonPath('error', 'revision_conflict');

        $this->assertTrue(WorkspaceMembership::query()->where('workspace_id', $this->owned->getKey())->where('user_id', $this->target->getKey())->exists());
    }

    /** A read pilot: until this deployment switches writes on, no update reaches the adapter. */
    public function test_updates_are_refused_until_writes_are_enabled_here(): void
    {
        config(['bherila-auth.delegated_access.writes_enabled' => false]);
        $revision = $this->revisionOf('target-subject');

        $this->send([
            'operation' => 'update',
            'subject' => 'target-subject',
            'expected_revision' => $revision,
            'access' => ['application_admin' => false, 'workspaces' => []],
        ])->assertStatus(403)->assertJsonPath('error', 'not_authorized');
        $this->validated($this->send(['operation' => 'read', 'subject' => 'target-subject']), 'read', 'target-subject');

        $this->assertTrue(WorkspaceMembership::query()->where('workspace_id', $this->owned->getKey())->where('user_id', $this->target->getKey())->exists());
    }

    public function test_an_update_may_not_reach_an_unmanaged_workspace_an_unknown_role_or_application_admin(): void
    {
        $revision = $this->revisionOf('target-subject');
        $update = fn (array $access): TestResponse => $this->send([
            'operation' => 'update', 'subject' => 'target-subject', 'expected_revision' => $revision, 'access' => $access,
        ]);

        $update(['application_admin' => false, 'workspaces' => [['id' => $this->elsewhere->public_id, 'role' => 'sender']]])->assertForbidden();
        $update(['application_admin' => false, 'workspaces' => [['id' => $this->owned->public_id, 'role' => 'emperor']]])->assertStatus(422);
        $update(['application_admin' => true, 'workspaces' => [['id' => $this->owned->public_id, 'role' => 'sender']]])->assertStatus(422);
    }

    public function test_an_administrator_cannot_change_an_owner_membership(): void
    {
        $admin = $this->bound('admin-subject', 'Example Admin');
        $this->member($this->administered, $admin, WorkspaceRole::Admin);
        $this->member($this->administered, $this->target, WorkspaceRole::Owner);

        $revision = $this->validated($this->send(['operation' => 'read', 'subject' => 'target-subject'], subject: 'admin-subject'), 'read', 'target-subject')['revision'];

        $this->send([
            'operation' => 'update', 'subject' => 'target-subject', 'expected_revision' => $revision,
            'access' => ['application_admin' => false, 'workspaces' => [['id' => $this->administered->public_id, 'role' => 'sender']]],
        ], subject: 'admin-subject')->assertForbidden();

        $this->assertSame(WorkspaceRole::Owner, WorkspaceMembership::query()->where('workspace_id', $this->administered->getKey())->where('user_id', $this->target->getKey())->sole()->role);
    }

    public function test_the_last_owner_cannot_be_removed_from_here(): void
    {
        $revision = $this->validated($this->send(['operation' => 'read', 'subject' => 'actor-subject']), 'read', 'actor-subject')['revision'];

        $this->send([
            'operation' => 'update', 'subject' => 'actor-subject', 'expected_revision' => $revision,
            'access' => ['application_admin' => false, 'workspaces' => [['id' => $this->administered->public_id, 'role' => 'admin']]],
        ])->assertStatus(422);

        $this->assertTrue(WorkspaceMembership::query()->where('workspace_id', $this->owned->getKey())->where('user_id', $this->actor->getKey())->exists());
    }

    // ------------------------------------------------------------------------ provisioning

    public function test_provisioning_creates_a_bound_account_with_the_memberships_asked_for(): void
    {
        $response = $this->validated($this->send([
            'operation' => 'update',
            'subject' => 'new-subject',
            'expected_revision' => null,
            'display_name' => 'Example Newcomer',
            'access' => ['application_admin' => false, 'workspaces' => [['id' => $this->owned->public_id, 'role' => 'sender']]],
        ]), 'update', 'new-subject');

        $this->assertTrue($response['provisioned']);

        $binding = IdentityBinding::query()->forIssuerSubject(self::PROVIDER, 'new-subject')->sole();
        $user = $binding->user;
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Example Newcomer', $user->name);
        $this->assertSame(PendingAccount::email(self::PROVIDER, 'new-subject'), $user->email);
        $this->assertNull($binding->last_seen_at);
        $this->assertSame(WorkspaceRole::Sender, WorkspaceMembership::query()->where('user_id', $user->getKey())->sole()->role);
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.user_provisioned')->count());
    }

    public function test_provisioning_an_existing_subject_is_a_conflict(): void
    {
        $this->send([
            'operation' => 'update', 'subject' => 'target-subject', 'expected_revision' => null,
            'access' => ['application_admin' => false, 'workspaces' => [['id' => $this->owned->public_id, 'role' => 'auditor']]],
        ])->assertStatus(409);

        $this->assertSame(WorkspaceRole::Sender, WorkspaceMembership::query()->where('workspace_id', $this->owned->getKey())->where('user_id', $this->target->getKey())->sole()->role);
    }

    public function test_provisioning_never_adopts_an_orphaned_account_found_by_its_address(): void
    {
        User::factory()->create(['email' => PendingAccount::email(self::PROVIDER, 'orphan-subject')]);

        $this->send([
            'operation' => 'update', 'subject' => 'orphan-subject', 'expected_revision' => null,
            'access' => ['application_admin' => false, 'workspaces' => [['id' => $this->owned->public_id, 'role' => 'sender']]],
        ])->assertStatus(422);

        $this->assertFalse(IdentityBinding::query()->forIssuerSubject(self::PROVIDER, 'orphan-subject')->exists());
    }

    /** Provisioning must grant something, so every account is created under a locked authority check (#127 review). */
    public function test_provisioning_without_a_membership_is_refused(): void
    {
        $this->send([
            'operation' => 'update', 'subject' => 'empty-subject', 'expected_revision' => null,
            'access' => ['application_admin' => false, 'workspaces' => []],
        ])->assertStatus(422);

        $this->assertFalse(IdentityBinding::query()->forIssuerSubject(self::PROVIDER, 'empty-subject')->exists());
    }

    /** The package default provider name is never trusted as the binding issuer (#127 review). */
    public function test_requests_are_refused_until_the_oauth_provider_is_set_explicitly(): void
    {
        config(['bherila-auth.delegated_access.oauth_provider' => '']);

        $response = $this->send(['operation' => 'capabilities']);

        $this->assertGreaterThanOrEqual(500, $response->status());
        $response->assertJsonMissingPath('controls');
    }

    /** Workspace labels respect the contract's 250-character bound (#127 review). */
    public function test_a_long_workspace_name_is_listed_within_the_label_bound(): void
    {
        $this->owned->forceFill(['name' => str_repeat('W', 255)])->save();

        $response = $this->validated($this->send(['operation' => 'workspaces']), 'workspaces');

        $this->assertSame(250, mb_strlen($response['workspaces'][0]['label']));
    }

    public function test_settings_refuse_a_key_list_with_an_unreadable_entry(): void
    {
        $this->assertCount(1, DelegatedAccessSettings::parsePublicKeys('integration-v1|'.$this->keyPath));
        $this->assertSame([], DelegatedAccessSettings::parsePublicKeys('integration-v1|'.$this->keyPath.',integration-v2|/nonexistent.pem'));
        $this->assertSame([], DelegatedAccessSettings::parsePublicKeys('no-separator'));
    }

    // ------------------------------------------------------------------------ helpers

    private function bound(string $subject, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        IdentityBinding::create(['user_id' => $user->getKey(), 'issuer' => self::PROVIDER, 'subject' => $subject]);

        return $user;
    }

    private function member(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function body(array $input): string
    {
        // Every write carries the provider's operation id, one per user action.
        if (in_array($input['operation'] ?? null, ['update', 'remove'], true)) {
            $input += ['operation_id' => DelegatedContract::operationId()];
        }

        return (string) json_encode(['contract_version' => DelegatedContract::VERSION_3, 'application' => self::APPLICATION, ...$input], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function send(array $input, string $subject = 'actor-subject', ?string $token = null, ?string $body = null): TestResponse
    {
        $body ??= $this->body($input);

        return $this->call('POST', '/application-access', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->assertion($subject, $body)),
        ], $body);
    }

    private function assertion(string $subject, string $body): string
    {
        $now = new DateTimeImmutable('@'.time());

        return Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->withHeader('typ', 'application-access+jwt')
            ->withHeader('kid', 'integration-v1')
            ->issuedBy(self::ISSUER)->relatedTo($subject)->permittedFor(self::ENDPOINT)
            ->issuedAt($now)->expiresAt($now->modify('+60 seconds'))
            ->identifiedBy(bin2hex(random_bytes(32)))
            ->withClaim('application', self::APPLICATION)
            ->withClaim('method', 'POST')
            ->withClaim('body_sha256', hash('sha256', $body))
            ->getToken(new Sha256, InMemory::plainText($this->privateKey))
            ->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(TestResponse $response, string $operation, ?string $subject = null): array
    {
        $response->assertOk();

        return (new DelegatedContract)->response($response->json(), self::APPLICATION, $operation, $subject, DelegatedContract::VERSION_3);
    }

    private function revisionOf(string $subject): string
    {
        return (string) $this->validated($this->send(['operation' => 'read', 'subject' => $subject]), 'read', $subject)['revision'];
    }
}
