<?php

namespace App\Models;

use App\Domain\Identity\Models\IdentityBinding;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A person who can sign in to the administrative surface.
 *
 * There is deliberately no `is_admin` column. Authority is a workspace membership row and
 * nothing else, so this model carries account *state* (can this person log in at all) and
 * contact data, never a role.
 *
 * `email` is contact data. It has no unique index, it is never an account-linking key, and
 * nothing may look a user up by it in order to decide who they are — see
 * `IdentityBinding::forIssuerSubject()`, which is the only supported identity lookup.
 *
 * @property Carbon|null $disabled_at
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether an operator has withdrawn this account's access.
     *
     * Read by App\Domain\Identity\Auth\EsignUserPolicy, which is the single gate every login
     * path goes through. Nothing else should branch on it, so there is one answer to
     * "may this person sign in" rather than one per entry point.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @return HasMany<IdentityBinding, $this>
     */
    public function identityBindings(): HasMany
    {
        return $this->hasMany(IdentityBinding::class);
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }
}
