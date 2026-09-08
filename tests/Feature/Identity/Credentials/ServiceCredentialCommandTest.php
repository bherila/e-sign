<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Credentials;

use App\Domain\Identity\Audit\AuditActor;
use App\Domain\Identity\Audit\AuditEvent;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\ServiceCredential;
use App\Domain\Identity\Credentials\ServiceCredentialIssuer;
use App\Domain\Identity\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The `esign:credential:*` operator commands.
 *
 * These are the only supported way to hold a plaintext secret, so the tests check both that
 * the printed secret really is the credential's and that no other command ever prints one.
 */
class ServiceCredentialCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    }

    public function test_issue_prints_a_working_secret_once_with_a_warning(): void
    {
        $exit = Artisan::call('esign:credential:issue', [
            '--workspace' => 'alpha',
            '--label' => 'consumer production',
            '--scope' => ['envelopes:read', 'envelopes:write'],
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Copy this secret now.', $output);
        $this->assertStringContainsString('cannot be recovered', $output);

        $secret = $this->secretIn($output);
        $credential = ServiceCredential::query()->sole();

        $this->assertTrue($credential->matches($secret), 'The printed secret must authenticate the stored credential.');
        $this->assertSame(['envelopes:read', 'envelopes:write'], $credential->scopes);
        $this->assertSame('consumer production', $credential->label);
        $this->assertSame($this->workspace->getKey(), $credential->workspace_id);
        $this->assertNull($credential->expires_at);
        $this->assertSame(1, substr_count($output, $secret), 'The secret is printed exactly once.');
        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.service_credential_issued')->count());
    }

    public function test_issue_accepts_a_public_id_and_a_comma_separated_scope_list(): void
    {
        $exit = Artisan::call('esign:credential:issue', [
            '--workspace' => $this->workspace->public_id,
            '--label' => 'imports',
            '--scope' => ['templates:read,templates:write'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(['templates:read', 'templates:write'], ServiceCredential::query()->sole()->scopes);
    }

    public function test_issue_accepts_an_interval_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        Artisan::call('esign:credential:issue', [
            '--workspace' => 'alpha',
            '--label' => 'temporary',
            '--scope' => ['envelopes:read'],
            '--expires' => '30d',
        ]);

        $this->assertSame(
            '2026-10-08 10:00:00',
            ServiceCredential::query()->sole()->expires_at?->toDateTimeString(),
        );
    }

    public function test_issue_refuses_without_a_workspace_a_label_or_a_scope(): void
    {
        $this->artisan('esign:credential:issue', ['--label' => 'x', '--scope' => ['envelopes:read']])
            ->expectsOutputToContain('--workspace is required.')
            ->assertFailed();

        $this->artisan('esign:credential:issue', ['--workspace' => 'alpha', '--scope' => ['envelopes:read']])
            ->expectsOutputToContain('--label is required.')
            ->assertFailed();

        $this->artisan('esign:credential:issue', ['--workspace' => 'alpha', '--label' => 'x'])
            ->expectsOutputToContain('At least one --scope is required.')
            ->assertFailed();

        $this->assertSame(0, ServiceCredential::query()->count());
    }

    public function test_issue_refuses_an_unknown_scope_and_lists_the_known_ones(): void
    {
        $exit = Artisan::call('esign:credential:issue', [
            '--workspace' => 'alpha',
            '--label' => 'x',
            '--scope' => ['envelopes:delete'],
        ]);

        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Unknown scope: 'envelopes:delete'", $output);
        // The refusal lists what is legal, so an operator does not have to read the enum.
        $this->assertStringContainsString('webhooks:manage', $output);
        $this->assertSame(0, ServiceCredential::query()->count());
    }

    public function test_issue_refuses_an_unknown_workspace(): void
    {
        $this->artisan('esign:credential:issue', [
            '--workspace' => 'nope',
            '--label' => 'x',
            '--scope' => ['envelopes:read'],
        ])
            ->expectsOutputToContain("No workspace matches 'nope'.")
            ->assertFailed();
    }

    public function test_rotate_prints_a_new_secret_and_names_the_cutover(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 10:00:00'));

        $original = app(ServiceCredentialIssuer::class)->issue(
            $this->workspace,
            'consumer production',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
        );

        $exit = Artisan::call('esign:credential:rotate', ['prefix' => $original->credential->prefix]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2026-09-09T10:00:00+00:00', $output);

        $secret = $this->secretIn($output);
        $successor = ServiceCredential::query()->where('rotated_from_id', $original->credential->getKey())->sole();

        $this->assertTrue($successor->matches($secret));
        $this->assertFalse($successor->matches($original->secret));
        $this->assertStringNotContainsString($this->secretHalf($original->secret), $output);
        $this->assertSame('2026-09-09 10:00:00', $original->credential->refresh()->expires_at?->toDateTimeString());
    }

    public function test_rotate_honours_a_zero_overlap(): void
    {
        $original = app(ServiceCredentialIssuer::class)->issue(
            $this->workspace,
            'leaked',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
        );

        $this->artisan('esign:credential:rotate', [
            'prefix' => $original->credential->prefix,
            '--overlap' => '0h',
        ])->assertSuccessful();

        $this->assertTrue($original->credential->refresh()->isExpired());
    }

    public function test_rotate_refuses_a_bad_overlap_and_an_unknown_prefix(): void
    {
        $original = app(ServiceCredentialIssuer::class)->issue(
            $this->workspace,
            'x',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
        );

        $this->artisan('esign:credential:rotate', ['prefix' => $original->credential->prefix, '--overlap' => 'soon'])
            ->expectsOutputToContain("'soon' is not an interval.")
            ->assertFailed();

        $this->artisan('esign:credential:rotate', ['prefix' => 'esk_zzzzzzzzzzzz'])
            ->expectsOutputToContain("No credential has the prefix 'esk_zzzzzzzzzzzz'.")
            ->assertFailed();
    }

    public function test_revoke_is_immediate_and_repeating_it_is_a_no_op(): void
    {
        $issued = app(ServiceCredentialIssuer::class)->issue(
            $this->workspace,
            'x',
            [Scope::EnvelopesRead->value],
            AuditActor::console('test'),
        );

        $this->artisan('esign:credential:revoke', [
            'prefix' => $issued->credential->prefix,
            '--reason' => 'pasted into a ticket',
        ])
            ->expectsOutputToContain('fails with 401')
            ->assertSuccessful();

        $this->assertNotNull($issued->credential->refresh()->revoked_at);

        $this->artisan('esign:credential:revoke', ['prefix' => $issued->credential->prefix])
            ->expectsOutputToContain('was already revoked')
            ->assertSuccessful();

        $this->assertSame(1, AuditEvent::query()->where('action', 'identity.service_credential_revoked')->count());
    }

    public function test_list_shows_state_without_any_secret(): void
    {
        $beta = Workspace::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        $issuer = app(ServiceCredentialIssuer::class);

        $alphaCredential = $issuer->issue($this->workspace, 'alpha key', [Scope::EnvelopesRead->value], AuditActor::console('test'));
        $betaCredential = $issuer->issue($beta, 'beta key', [Scope::WebhooksManage->value], AuditActor::console('test'));
        $issuer->revoke($betaCredential->credential, AuditActor::console('test'));

        Artisan::call('esign:credential:list');
        $all = Artisan::output();

        $this->assertStringContainsString($alphaCredential->credential->prefix, $all);
        $this->assertStringContainsString($betaCredential->credential->prefix, $all);
        $this->assertStringContainsString('revoked', $all);
        $this->assertStringNotContainsString($this->secretHalf($alphaCredential->secret), $all);
        $this->assertStringNotContainsString($this->secretHalf($betaCredential->secret), $all);

        Artisan::call('esign:credential:list', ['--workspace' => 'alpha']);
        $alphaOnly = Artisan::output();

        $this->assertStringContainsString($alphaCredential->credential->prefix, $alphaOnly);
        $this->assertStringNotContainsString($betaCredential->credential->prefix, $alphaOnly);
    }

    public function test_list_says_so_when_a_workspace_has_no_credentials(): void
    {
        $this->artisan('esign:credential:list', ['--workspace' => 'alpha'])
            ->expectsOutputToContain("No API credentials have been issued in 'alpha'.")
            ->assertSuccessful();
    }

    private function secretIn(string $output): string
    {
        $this->assertMatchesRegularExpression('/esk_[a-z0-9]{12}_[a-z0-9]{43}/', $output);
        preg_match('/esk_[a-z0-9]{12}_[a-z0-9]{43}/', $output, $matches);

        return $matches[0];
    }

    private function secretHalf(string $secret): string
    {
        return substr($secret, (int) strrpos($secret, '_') + 1);
    }
}
