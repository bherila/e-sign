<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Models\User;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The tenancy boundary. Authority inside a workspace comes only from a membership row.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $workspace): void {
            if (($workspace->public_id ?? '') === '') {
                $workspace->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * Bind route parameters on the public identifier, never the autoincrement id.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function membershipFor(User $user): ?WorkspaceMembership
    {
        if (! $user->exists) {
            return null;
        }

        if ($this->relationLoaded('memberships')) {
            return $this->memberships->firstWhere('user_id', $user->getKey());
        }

        return $this->memberships()->where('user_id', $user->getKey())->first();
    }

    public function roleFor(User $user): ?WorkspaceRole
    {
        return $this->membershipFor($user)?->role;
    }

    /**
     * The only supported way to look a workspace up on behalf of a person.
     *
     * Every lookup by id, public id, or slug must go through this scope. A workspace the
     * caller is not a member of then does not exist as far as the query is concerned, which
     * is what keeps cross-workspace probing from distinguishing "forbidden" from "absent".
     *
     * @param  Builder<Workspace>  $query
     * @return Builder<Workspace>
     */
    public function scopeWhereMemberOf(Builder $query, User $user): Builder
    {
        if (! $user->exists) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'memberships',
            fn (Builder $memberships): Builder => $memberships->where('user_id', $user->getKey()),
        );
    }

    protected static function newFactory(): Factory
    {
        return WorkspaceFactory::new();
    }
}
