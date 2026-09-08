<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The permission map is the single source of authorization truth, so it is asserted
 * directly as well as through the policy.
 */
class WorkspaceRoleTest extends TestCase
{
    /**
     * @return array<string, array{0: WorkspaceRole, 1: WorkspacePermission, 2: bool}>
     */
    public static function rolePermissionMatrix(): array
    {
        $matrix = [
            'owner' => [
                'view' => true, 'update' => true, 'delete' => true, 'manageMembers' => true,
                'createTemplates' => true, 'createEnvelopes' => true, 'readAudit' => true,
                'rotateCredentials' => true,
            ],
            'admin' => [
                'view' => true, 'update' => true, 'delete' => false, 'manageMembers' => true,
                'createTemplates' => true, 'createEnvelopes' => true, 'readAudit' => true,
                'rotateCredentials' => false,
            ],
            'sender' => [
                'view' => true, 'update' => false, 'delete' => false, 'manageMembers' => false,
                'createTemplates' => true, 'createEnvelopes' => true, 'readAudit' => true,
                'rotateCredentials' => false,
            ],
            'auditor' => [
                'view' => true, 'update' => false, 'delete' => false, 'manageMembers' => false,
                'createTemplates' => false, 'createEnvelopes' => false, 'readAudit' => true,
                'rotateCredentials' => false,
            ],
        ];

        $cases = [];

        foreach ($matrix as $role => $permissions) {
            foreach ($permissions as $permission => $expected) {
                $cases["{$role} {$permission}"] = [
                    WorkspaceRole::from($role),
                    WorkspacePermission::from($permission),
                    $expected,
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('rolePermissionMatrix')]
    public function test_permission_map_covers_every_role_and_permission(
        WorkspaceRole $role,
        WorkspacePermission $permission,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            $role->can($permission),
            "{$role->value} should ".($expected ? '' : 'not ')."have {$permission->value}",
        );
    }

    public function test_the_matrix_is_exhaustive(): void
    {
        $expectedCases = count(WorkspaceRole::cases()) * count(WorkspacePermission::cases());

        $this->assertCount($expectedCases, self::rolePermissionMatrix());
    }

    public function test_roles_are_ordered_owner_admin_sender_auditor(): void
    {
        $ranks = array_map(
            static fn (WorkspaceRole $role): int => $role->rank(),
            [WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Sender, WorkspaceRole::Auditor],
        );

        $sorted = $ranks;
        rsort($sorted);

        $this->assertSame($sorted, $ranks);
        $this->assertCount(count(WorkspaceRole::cases()), array_unique($ranks));
    }

    public function test_values_are_the_stored_strings(): void
    {
        $this->assertSame(['owner', 'admin', 'sender', 'auditor'], WorkspaceRole::values());
    }
}
