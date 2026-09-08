<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Credentials;

use App\Domain\Identity\Credentials\MissingScope;
use App\Domain\Identity\Credentials\Scope;
use App\Domain\Identity\Credentials\UnknownScope;
use PHPUnit\Framework\TestCase;

/**
 * The scope list and its resolution rules, asserted directly as well as through the
 * middleware, because "unknown scopes are rejected at issue time" is the property that
 * keeps unenforceable values out of the database.
 */
class ScopeTest extends TestCase
{
    public function test_the_initial_scope_set_is_fixed(): void
    {
        $this->assertSame([
            'envelopes:read',
            'envelopes:write',
            'templates:read',
            'templates:write',
            'webhooks:manage',
            'compat:firma-v1',
        ], Scope::values());
    }

    public function test_an_unknown_scope_is_rejected_and_names_itself(): void
    {
        $this->expectException(UnknownScope::class);
        $this->expectExceptionMessage("Unknown scope: 'envelopes:delete'");

        Scope::fromValues(['envelopes:read', 'envelopes:delete']);
    }

    public function test_one_unknown_scope_rejects_the_whole_list(): void
    {
        try {
            Scope::fromValues(['envelopes:read', 'nope']);
            $this->fail('Expected UnknownScope.');
        } catch (UnknownScope $exception) {
            // The known member must not be quietly kept: a credential issued with less
            // authority than was asked for is a support ticket in three weeks.
            $this->assertStringContainsString('Known scopes: envelopes:read', $exception->getMessage());
        }
    }

    public function test_non_string_values_are_rejected(): void
    {
        $this->expectException(UnknownScope::class);

        Scope::fromValues(['envelopes:read', 42]);
    }

    public function test_resolution_is_canonical_and_deduplicated(): void
    {
        $resolved = Scope::fromValues([' Envelopes:Write ', 'envelopes:read', 'envelopes:write', '']);

        $this->assertSame(
            [Scope::EnvelopesRead, Scope::EnvelopesWrite],
            $resolved,
            'Scopes must come back in enum order with duplicates collapsed so stored lists compare equal.',
        );
    }

    public function test_write_does_not_imply_read(): void
    {
        $granted = [Scope::EnvelopesWrite];

        $this->assertTrue(Scope::EnvelopesWrite->satisfiedBy($granted));
        $this->assertFalse(Scope::EnvelopesRead->satisfiedBy($granted));
    }

    public function test_the_compatibility_scope_grants_no_resource_access(): void
    {
        $granted = [Scope::CompatFirmaV1];

        foreach ([Scope::EnvelopesRead, Scope::EnvelopesWrite, Scope::TemplatesRead, Scope::TemplatesWrite, Scope::WebhooksManage] as $scope) {
            $this->assertFalse(
                $scope->satisfiedBy($granted),
                "compat:firma-v1 must not grant {$scope->value}; the facade calls the same domain services as the native API.",
            );
        }
    }

    public function test_requires_scope_throws_when_the_grant_is_absent(): void
    {
        $this->expectException(MissingScope::class);
        $this->expectExceptionMessage("This credential is not granted 'webhooks:manage'. Granted: envelopes:read.");

        Scope::WebhooksManage->requiresScope([Scope::EnvelopesRead]);
    }

    public function test_requires_scope_is_silent_when_the_grant_is_present(): void
    {
        Scope::EnvelopesRead->requiresScope([Scope::EnvelopesRead, Scope::WebhooksManage]);

        $this->assertTrue(true, 'requiresScope() returns nothing and throws nothing when the scope is held.');
    }

    public function test_a_single_scope_string_resolves(): void
    {
        $this->assertSame(Scope::CompatFirmaV1, Scope::fromValue('compat:firma-v1'));
    }
}
