<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Services\WorkspaceMembers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use, expiring invitation to join one workspace in one role.
 *
 * Holds a digest of its token, never the token. Whoever redeems it while signed in becomes the
 * member, so no email address, name or directory listing takes part in deciding who that is.
 * Created, redeemed and revoked only through
 * {@see WorkspaceMembers}.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property WorkspaceRole $role
 * @property string $token_sha256
 * @property int|null $created_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $redeemed_at
 * @property int|null $redeemed_by
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 */
class WorkspaceInvitation extends Model
{
    protected $fillable = [
        'workspace_id',
        'role',
        'token_sha256',
        'created_by',
        'expires_at',
    ];

    protected $hidden = [
        'token_sha256',
    ];

    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->public_id ??= (string) Str::ulid();
        });
    }

    /** Whether the link can still be used: not redeemed, not revoked, not expired. */
    public function isOpen(?CarbonImmutable $now = null): bool
    {
        return $this->redeemed_at === null
            && $this->revoked_at === null
            && $this->expires_at->greaterThan($now ?? CarbonImmutable::now());
    }

    /**
     * @param  Builder<WorkspaceInvitation>  $query
     * @return Builder<WorkspaceInvitation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('redeemed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', CarbonImmutable::now());
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
