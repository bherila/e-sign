<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Templates\Models;

use App\Domain\Preparation\Documents\Models\DocumentRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member of a template version's document set (issue #163). Written with the draft and
 * replaced only while the version is a draft; a published version's set is copied into every
 * envelope built from it (docs/preparation/document-sets.md).
 *
 * @property int $id
 * @property int $template_version_id
 * @property int $position
 * @property int $document_revision_id
 * @property string $title
 * @property string $sha256
 * @property int $page_count
 * @property int $first_page
 */
class TemplateVersionDocument extends Model
{
    protected $fillable = [
        'template_version_id',
        'position',
        'document_revision_id',
        'title',
        'sha256',
        'page_count',
        'first_page',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'page_count' => 'integer',
            'first_page' => 'integer',
        ];
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /** @return BelongsTo<DocumentRevision, $this> */
    public function documentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class);
    }
}
