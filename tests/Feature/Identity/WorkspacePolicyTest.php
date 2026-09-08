<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\WorkspacePermission;
use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every role x action combination, checked through the Gate so policy registration is part
 * of what is under test.
 */
class WorkspacePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function roleAbilityMatrix(): array
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

        foreach ($matrix as $role => $abilities) {
            foreach ($abilities as $ability => $expected) {
                $cases["{$role} may ".($expected ? '' : 'not ').$ability] = [$role, $ability, $expected];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function abilities(): array
    {
        $cases = [];

        foreach (WorkspacePermission::cases() as $permission) {
            $cases[$permission->value] = [$permission->value];
        }

        return $cases;
    }

    #[DataProvider('roleAbilityMatrix')]
    public function test_role_ability_matrix(string $role, string $ability, bool $expected): void
    {
        $workspace = Workspace::factory()->create();
        $user = $this->memberOf($workspace, WorkspaceRole::from($role));

        $this->assertSame(
            $expected,
            $user->can($ability, $workspace),
            "{$role} should ".($expected ? '' : 'not ')."be allowed to {$ability}",
        );
    }

    #[DataProvider('abilities')]
    public function test_a_user_with_no_membership_is_denied_every_ability(string $ability): void
    {
        $workspace = Workspace::factory()->create();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->can($ability, $workspace));
    }

    #[DataProvider('abilities')]
    public function test_membership_of_another_workspace_grants_nothing_here(string $ability): void
    {
        $theirs = Workspace::factory()->create();
        $ours = Workspace::factory()->create();

        // Owner elsewhere: the most privileged role there must still be nothing here.
        $outsider = $this->memberOf($theirs, WorkspaceRole::Owner);

        $this->assertFalse($outsider->can($ability, $ours));
    }

    public function test_the_matrix_covers_every_role_and_every_ability(): void
    {
        $this->assertCount(
            count(WorkspaceRole::cases()) * count(WorkspacePermission::cases()),
            self::roleAbilityMatrix(),
        );
    }

    public function test_there_is_no_super_user_role(): void
    {
        // A local user row on its own carries no authority anywhere. There is no is_admin
        // column, no first-user promotion, and no global override to find.
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        foreach (WorkspacePermission::cases() as $permission) {
            $this->assertFalse($user->can($permission->value, $workspace));
        }

        $this->assertSame(0, WorkspaceMembership::count());
    }

    private function memberOf(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return $user;
    }
}
