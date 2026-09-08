<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates\Models;

use App\Domain\Identity\Models\Workspace;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A reusable preparation inside one workspace.
 *
 * The mutable handle: its name, its description, and which of its versions is current. None
 * of the content a sender sends is on this row — that is all on
 * {@see TemplateVersion}, which is frozen once published, so a rename here can never change
 * what an envelope already out for signature says.
 *
 * `current_version_id` is written only by
 * App\Domain\Preparation\Templates\TemplateService::publish(). It has no foreign key; see the
 * templates migration for why (a circular constraint cannot be added to an existing table on
 * SQLite, and a constraint present on one engine only is worse than none).
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property int|null $current_version_id
 * @property CarbonInterface|null $retired_at
 * @property int|null $created_by
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'current_version_id',
        'retired_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            if (($template->public_id ?? '') === '') {
                $template->public_id = (string) Str::ulid();
            }
        });
    }

    /** Bind route parameters on the public identifier, never the autoincrement id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    /** @return HasMany<TemplateAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(TemplateAlias::class);
    }

    /**
     * The published version a sender gets when they pick this template, or null before the
     * first publish.
     *
     * Not a `belongsTo`: `current_version_id` deliberately has no foreign key, and a
     * relationship would invite an eager load that implies one exists. The lookup is scoped
     * to this template's own versions, so a stale or mis-set id resolves to null rather than
     * to somebody else's version.
     */
    public function currentVersion(): ?TemplateVersion
    {
        if ($this->current_version_id === null) {
            return null;
        }

        if ($this->relationLoaded('versions')) {
            return $this->versions->firstWhere('id', $this->current_version_id);
        }

        return $this->versions()->whereKey($this->current_version_id)->first();
    }

    public function versionNumbered(int $version): ?TemplateVersion
    {
        return $this->versions()->where('version', $version)->first();
    }

    /** The highest version number in use, or 0 on a template with no versions yet. */
    public function highestVersionNumber(): int
    {
        return (int) $this->versions()->max('version');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * The only supported way to look a template up on behalf of a person.
     *
     * Scoping on the workspace rather than filtering afterwards is what makes a
     * cross-workspace probe by autoincrement id or by public ULID indistinguishable from a
     * template that does not exist.
     *
     * @param  Builder<Template>  $query
     * @return Builder<Template>
     */
    public function scopeInWorkspace(Builder $query, Workspace $workspace): Builder
    {
        return $query->where('workspace_id', $workspace->getKey());
    }

    protected static function newFactory(): Factory
    {
        return TemplateFactory::new();
    }
}
