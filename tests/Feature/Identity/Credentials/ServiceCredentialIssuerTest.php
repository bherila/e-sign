<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Credentials;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Credentials\IssuedServiceCredential;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Credentials\UnknownScope;
use App\Domain\Identity\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue, rotate, and revoke.
 *
 * The properties asserted here are the ones a later refactor could break silently: the
 * plaintext is nowhere in the database, rotation overlaps rather than cuts, revocation is
 * immediate and not repeated, and every operation leaves an audit event that does not
 * contain the secret.
 */
class ServiceCredentialIssuerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ServiceCredentialIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->issuer = app(ServiceCredentialIssuer::class);
    }

    public function test_issuing_returns_the_plaintext_once_and_stores_only_a_digest(): void
    {
        $issued = $this->issue([Scope::EnvelopesRead->value]);

        $this->assertMatchesRegularExpression('/^esk_[a-z0-9]{12}$/', $issued->credential->prefix);
        $this->assertStringStartsWith($issued->credential->prefix.'_', $issued->secret);
        $this->assertTrue($issued->credential->matches($issued->secret));
        $this->assertFalse($issued->credential->matches($issued->secret.'x'));

        // Nothing in the row, in any column, is the plaintext or contains it.
        $row = (array) DB::table('service_credentials')->where('id', $issued->credential->getKey())->first();

        foreach ($row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            $this->assertStringNotContainsString(
                $this->secretHalf($issued->secret),
                $value,
                "service_credentials.{$column} must not contain the plaintext secret.",
            );
        }

        $this->assertSame(64, strlen((string) $row['secret_hash']), 'sha256 hex digest.');
        $this->assertSame(32, strlen((string) $row['secret_salt']), '128-bit salt, hex encoded.');
    }

    public function test_two_credentials_never_share_a_salt_or_a_prefix(): void
    {
        $first = $this->issue([Scope::EnvelopesRead->value]);
        $second = $this->issue([Scope::EnvelopesRead->value]);

        $this->assertNotSame($first->credential->prefix, $second->credential->prefix);
        $this->assertNotSame($first->credential->secret_salt, $second->credential->secret_salt);
        $this->assertNotSame($first->credential->secret_hash, $second->credential->secret_hash);
    }

    public function test_the_secret_is_not_serialised_with_the_model(): void
    {
        $issued = $this->issue([Scope::EnvelopesRead->value]);

        $json = $issued->credential->toJson();

        $this->assertStringNotContainsString('secret_hash', $json);
        $this->assertStringNotContainsString('secret_salt', $json);
        $this->assertStringNotContainsString($this->secretHalf($issued->secret), $json);
    }

    public function test_issuing_records_an_audit_event_without_the_secret(): void
    {
        $user = User::factory()->create(['name' => 'Dana Operator']);

        $issued = $this->issuer->issue(
            $this->workspace,
            'consumer production',
            [Scope::EnvelopesRead->value, Scope::CompatFirmaV1->value],
            AuditActor::user($user),
        );

        $event = AuditEvent::query()->where('action', 'identity.service_credential_issued')->sole();

        $this->assertSame('user', $event->actor_type);
        $this->assertSame((string) $user->getKey(), $event->actor_id);
        $this->assertSame(ServiceCredential::class, $event->subject_type);
        $this->assertSame((string) $issued->credential->getKey(), $event->subject_id);
        $this->assertSame($issued->credential->prefix, $event->payload['credential_prefix'] ?? null);
        $this->assertSame(['envelopes:read', 'compat:firma-v1'], $event->payload['scopes'] ?? null);
        $this->assertStringNotContainsString(
            $this->secretHalf($issued->secret),
            (string) json_encode($event->payload),
            'An audit payload records the prefix, never the secret.',
        );
    }

    public function test_an_unknown_scope_is_rejected_at_issue_time_and_creates_nothing(): void
    {
        try {
            $this->issue(['envelopes:read', 'envelopes:destroy']);
            $this->fail('Expected UnknownScope.');
        } catch (UnknownScope $exception) {
            $this->assertStringContainsString("'envelopes:destroy'", $exception->getMessage());
        }

        $this->assertSame(0, ServiceCredential::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_a_credential_with_no_scopes_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A credential needs at least one scope.');

        $this->issue([]);
    }

    public function test_a_blank_label_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A credential needs a label.');

        $this->issuer->issue($this->workspace, '   ', [Scope::EnvelopesRead->value], AuditActor::console('test'));
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is in the past');

        $this->issuer->issue(
            $this->workspace,
            'stale',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
            CarbonImmutable::now()->subMinute(),
        );
    }

    public function test_a_deleted_workspace_cannot_be_given_credentials(): void
    {
        $this->workspace->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Workspace 'alpha' is deleted.");

        $this->issue([Scope::EnvelopesRead->value]);
    }

    public function test_rotation_mints_a_successor_and_gives_the_predecessor_a_deadline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $original = $this->issue([Scope::EnvelopesRead->value, Scope::EnvelopesWrite->value]);
        $rotated = $this->issuer->rotate($original->credential, AuditActor::console('test'));

        $this->assertNotSame($original->secret, $rotated->secret);
        $this->assertNotSame($original->credential->prefix, $rotated->credential->prefix);
        $this->assertSame($original->credential->getKey(), $rotated->credential->rotated_from_id);
        $this->assertSame($original->credential->label, $rotated->credential->label);
        $this->assertSame($this->workspace->getKey(), $rotated->credential->workspace_id);
        $this->assertSame(['envelopes:read', 'envelopes:write'], $rotated->credential->scopes);
        $this->assertNull($rotated->credential->expires_at, 'The predecessor had no expiry, so neither does the successor.');

        // Twenty-four hours of overlap by default, on the old credential only.
        $this->assertSame(
            '2026-09-09 10:00:00',
            $original->credential->refresh()->expires_at?->toDateTimeString(),
        );
        $this->assertTrue($original->credential->isUsable(), 'The old secret still works during the overlap.');
        $this->assertNull($original->credential->revoked_at, 'Rotation expires the old secret; it does not revoke it.');
    }

    public function test_rotation_never_extends_the_predecessors_own_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $original = $this->issuer->issue(
            $this->workspace,
            'short lived',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
            CarbonImmutable::parse('2026-09-08 12:00:00'),
        );

        $rotated = $this->issuer->rotate($original->credential, AuditActor::console('test'), CarbonInterval::hours(24));

        $this->assertSame(
            '2026-09-08 12:00:00',
            $original->credential->refresh()->expires_at?->toDateTimeString(),
            'A 24 hour overlap must not resurrect a credential that was due to expire in two hours.',
        );
        $this->assertSame(
            '2026-09-08 12:00:00',
            $rotated->credential->expires_at?->toDateTimeString(),
            'The successor inherits the expiry the credential was issued with.',
        );
    }

    public function test_a_zero_overlap_cuts_the_old_secret_over_immediately(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $original = $this->issue([Scope::EnvelopesRead->value]);
        $this->issuer->rotate($original->credential, AuditActor::console('test'), CarbonInterval::minutes(0));

        $this->assertTrue($original->credential->refresh()->isExpired());
    }

    public function test_rotation_records_an_audit_event_naming_both_prefixes(): void
    {
        $original = $this->issue([Scope::EnvelopesRead->value]);
        $rotated = $this->issuer->rotate($original->credential, AuditActor::console('esign:credential:rotate'));

        $event = AuditEvent::query()->where('action', 'identity.service_credential_rotated')->sole();

        $this->assertSame('cli', $event->actor_type);
        $this->assertSame($rotated->credential->prefix, $event->payload['credential_prefix'] ?? null);
        $this->assertSame($original->credential->prefix, $event->payload['rotated_from_prefix'] ?? null);
        $this->assertSame(86400, $event->payload['overlap_seconds'] ?? null);
        $this->assertStringNotContainsString(
            $this->secretHalf($rotated->secret),
            (string) json_encode($event->payload),
        );
    }

    public function test_a_revoked_credential_is_not_rotated(): void
    {
        $issued = $this->issue([Scope::EnvelopesRead->value]);
        $this->issuer->revoke($issued->credential, AuditActor::console('test'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is revoked');

        $this->issuer->rotate($issued->credential, AuditActor::console('test'));
    }

    public function test_an_expired_credential_is_not_rotated(): void
    {
        $issued = $this->issue([Scope::EnvelopesRead->value]);
        $issued->credential->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired at');

        $this->issuer->rotate($issued->credential, AuditActor::console('test'));
    }

    public function test_revocation_is_immediate_and_audited(): void
    {
        $issued = $this->issue([Scope::EnvelopesRead->value]);

        $revoked = $this->issuer->revoke($issued->credential, AuditActor::console('test'), 'pasted into a ticket');

        $this->assertNotNull($revoked->revoked_at);
        $this->assertFalse($revoked->isUsable());
        $this->assertSame('revoked', $revoked->status());

        $event = AuditEvent::query()->where('action', 'identity.service_credential_revoked')->sole();
        $this->assertSame('pasted into a ticket', $event->payload['reason'] ?? null);
        $this->assertSame($issued->credential->prefix, $event->payload['credential_prefix'] ?? null);
    }

    public function test_revoking_twice_writes_no_second_audit_event(): void
    {
        $issued = $this->issue([Scope::EnvelopesRead->value]);

        $this->issuer->revoke($issued->credential, AuditActor::console('test'));
        $revokedAt = $issued->credential->refresh()->revoked_at;

        $this->travel(1)->hour();
        $this->issuer->revoke($issued->credential, AuditActor::console('test'));

        $this->assertEquals($revokedAt, $issued->credential->refresh()->revoked_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.service_credential_revoked')->count());
    }

    public function test_a_workspace_holding_credentials_cannot_be_hard_deleted(): void
    {
        $this->issue([Scope::EnvelopesRead->value]);

        // RESTRICT, matching every other evidence-bearing foreign key onto workspaces.id.
        $this->expectException(QueryException::class);

        DB::table('workspaces')->where('id', $this->workspace->getKey())->delete();
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issue(array $scopes): IssuedServiceCredential
    {
        return $this->issuer->issue(
            $this->workspace,
            'consumer production',
            $scopes,
            AuditActor::console('test'),
        );
    }

    /**
     * The random half of a plaintext secret: the part that must never appear anywhere.
     */
    private function secretHalf(string $secret): string
    {
        return substr($secret, (int) strrpos($secret, '_') + 1);
    }
}
