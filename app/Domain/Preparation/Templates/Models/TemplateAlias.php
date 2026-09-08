<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates\Models;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Templates\TemplateAliasSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody else's identifier for one of our templates.
 *
 * docs/HANDOFF.md section 6 keeps native ids and imported-provider aliases in separate
 * fields, and section 2 says why: the consumer hardcodes provider template ids, so those ids
 * have to keep resolving after migration without ever becoming a template's own id.
 *
 * Added or removed, never edited — there is a `created_at` and no `updated_at` — so a rename
 * cannot quietly repoint a provider id at a different agreement. `workspace_id` is carried
 * here so the "one alias per workspace" rule is a database constraint rather than a
 * check-then-insert race; see the template_aliases migration.
 *
 * @property int $id
 * @property int $template_id
 * @property int $workspace_id
 * @property string $alias
 * @property TemplateAliasSource $source
 * @property CarbonInterface|null $created_at
 */
class TemplateAlias extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'template_id',
        'workspace_id',
        'alias',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'source' => TemplateAliasSource::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Aliases visible to one workspace.
     *
     * Resolution goes through this, so an alias another tenant registered is not merely
     * forbidden — it does not resolve at all, which is what keeps a provider id from leaking
     * the existence of another tenant's template.
     *
     * @param  Builder<TemplateAlias>  $query
     * @return Builder<TemplateAlias>
     */
    public function scopeInWorkspace(Builder $query, Workspace $workspace): Builder
    {
        return $query->where('workspace_id', $workspace->getKey());
    }
}
