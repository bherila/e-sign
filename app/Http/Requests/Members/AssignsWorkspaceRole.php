<?php

declare(strict_types=1);

namespace App\Http\Requests\Members;

use App\Domain\Identity\Enums\WorkspaceRole;
use Illuminate\Validation\Rule;

/**
 * A members action whose body names a role.
 *
 * The rule accepts every role, `owner` included. Whether *this* actor may grant the one they
 * named is decided by `WorkspaceMembers`, which knows the actor's own role and the row being
 * changed; a validation rule that tried would be a second, drifting copy of that decision.
 */
abstract class AssignsWorkspaceRole extends WorkspaceMembersRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(WorkspaceRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'Choose a role.',
            'role.enum' => 'Choose one of the roles this workspace offers: owner, admin, sender or auditor.',
        ];
    }

    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from((string) $this->validated('role'));
    }
}
