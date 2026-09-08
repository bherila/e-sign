<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's role in one workspace.
 *
 * Deleting this row removes access and nothing else. See the migration for the full
 * no-cascade rule; the short version is that nothing in the schema references a membership
 * id, so revoking access can never reach an envelope, an artifact, or an audit event.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 */
class WorkspaceMembership extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
