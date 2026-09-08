<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

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
    // Lets Workspace::members() hand back this class, with its role cast,
    // instead of a generic Pivot. Both key-setting overrides AsPivot brings
    // take the ordinary path whenever the primary key is set, which it always
    // is for a persisted row, so the model keeps behaving as a normal model.
    use AsPivot;

    // Explicit because AsPivot::getTable() would otherwise derive the singular
    // 'workspace_membership' from the class name and every query would miss.
    protected $table = 'workspace_memberships';

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
