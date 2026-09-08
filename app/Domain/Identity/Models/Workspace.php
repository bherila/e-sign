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
 * @property bool|null $require_otp
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        // Whether guests signing this workspace's agreements must answer a mailed code as
        // well as follow their invitation link. Null means "not decided here": the
        // deployment default in config('esign.signing.require_otp') applies, and an
        // individual envelope may still override both. See
        // App\Domain\Signing\Sessions\OtpRequirement.
        'require_otp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'require_otp' => 'boolean',
        ];
    }

    /**
     * Per-user membership lookups already resolved on this instance.
     *
     * @var array<int|string, WorkspaceMembership|null>
     */
    protected array $resolvedMemberships = [];

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
        // using() matters: without it the pivot is a generic Pivot with no
        // casts, so `$member->pivot->role` came back a raw string while the
        // identical-looking `$membership->role` was a WorkspaceRole. Comparing
        // the string to the enum is silently false, and calling ->can() on it
        // is a fatal — a trap that only shows up at the call site.
        return $this->belongsToMany(User::class, 'workspace_memberships')
            ->using(WorkspaceMembership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * This person's membership row, or null when they have none.
     *
     * Resolved once per user for the lifetime of this instance, so a template
     * asking `@can('update')` and then `@can('delete')` over a list of
     * workspaces does not issue two queries per row.
     *
     * The cache is per-instance and is not invalidated by a membership written
     * through some other object; re-read the workspace after granting access.
     */
    public function membershipFor(User $user): ?WorkspaceMembership
    {
        if (! $user->exists) {
            return null;
        }

        $userId = $user->getKey();

        if (array_key_exists($userId, $this->resolvedMemberships)) {
            return $this->resolvedMemberships[$userId];
        }

        if ($this->relationLoaded('memberships')) {
            $loaded = $this->memberships->firstWhere('user_id', $userId);

            // A hit in a loaded relation is always genuine, because an eager
            // load can only ever return a subset of the rows — never an extra
            // one. A miss is not conclusive: `with(['memberships' => fn ($q) =>
            // $q->where('role', 'owner')])`, which is what a workspace index
            // does to display owners, leaves every other member looking like a
            // non-member. That made $user->can('view', $workspace) false on a
            // workspace the person is actually in, so a miss falls through to a
            // query instead of being believed.
            if ($loaded instanceof WorkspaceMembership) {
                return $this->resolvedMemberships[$userId] = $loaded;
            }
        }

        return $this->resolvedMemberships[$userId] = $this->memberships()
            ->where('user_id', $userId)
            ->first();
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
