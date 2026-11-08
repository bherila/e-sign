<?php

declare(strict_types=1);

namespace App\Domain\Integration\Firma\Uploads;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A temporary upload the facade's `POST /documents` issued (issue #182).
 *
 * Its `public_id` is the `document_id` a caller passes to `document_id`, `document_ids`, or a
 * draft's `/documents` routes. It is not the document's own id: the document only exists once
 * the bytes have arrived and passed intake, and one upload is used once
 * ({@see FirmaDocumentUploads}).
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int|null $service_credential_id
 * @property string $file_name
 * @property string $content_type
 * @property int|null $declared_bytes
 * @property int|null $document_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $uploaded_at
 * @property CarbonImmutable|null $consumed_at
 * @property-read Document|null $document
 */
class FirmaDocumentUpload extends Model
{
    protected $table = 'firma_document_uploads';

    protected $fillable = [
        'workspace_id',
        'service_credential_id',
        'file_name',
        'content_type',
        'declared_bytes',
        'document_id',
        'expires_at',
        'uploaded_at',
        'consumed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $upload): void {
            if (($upload->public_id ?? '') === '') {
                $upload->public_id = (string) Str::ulid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'declared_bytes' => 'integer',
            'expires_at' => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInWorkspace(Builder $query, Workspace $workspace): Builder
    {
        return $query->where('workspace_id', $workspace->getKey());
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }
}
