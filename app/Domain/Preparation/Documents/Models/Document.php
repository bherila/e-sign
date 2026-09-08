<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents\Models;

use App\Domain\Identity\Models\Workspace;
use App\Domain\Preparation\Documents\DocumentStatus;
use App\Domain\Preparation\Documents\RevisionKind;
use App\Models\User;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An uploaded PDF inside one workspace.
 *
 * The `original_*` attributes describe the bytes as received and are written once, at
 * intake. Nothing in the application updates them; `App\Domain\Preparation\Documents\DocumentIntake`
 * is the only writer, and it writes them in the same transaction as the revision rows.
 *
 * `original_disk` and `original_path` never leave the server. They are excluded from every
 * HTTP representation (see App\Http\Resources\Documents\DocumentResource) because a client
 * that knows a storage key is one presigning bug away from bypassing the workspace policy.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $title
 * @property int|null $uploaded_by
 * @property string $original_disk
 * @property string $original_path
 * @property string $original_sha256
 * @property int $original_bytes
 * @property string $original_mime
 * @property int|null $page_count
 * @property array<string, mixed>|null $preflight_report
 * @property DocumentStatus $status
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'title',
        'uploaded_by',
        'original_disk',
        'original_path',
        'original_sha256',
        'original_bytes',
        'original_mime',
        'page_count',
        'preflight_report',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'preflight_report' => 'array',
            'status' => DocumentStatus::class,
            'original_bytes' => 'integer',
            'page_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $document): void {
            if (($document->public_id ?? '') === '') {
                $document->public_id = (string) Str::ulid();
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<DocumentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class);
    }

    public function revisionOfKind(RevisionKind $kind): ?DocumentRevision
    {
        if ($this->relationLoaded('revisions')) {
            return $this->revisions->first(
                static fn (DocumentRevision $revision): bool => $revision->kind === $kind,
            );
        }

        return $this->revisions()->where('kind', $kind->value)->first();
    }

    public function originalRevision(): ?DocumentRevision
    {
        return $this->revisionOfKind(RevisionKind::Original);
    }

    /**
     * The revision a signer may be shown. Null until intake has recorded one, and null
     * forever on a document that failed preflight.
     */
    public function reviewRevision(): ?DocumentRevision
    {
        return $this->revisionOfKind(RevisionKind::Review);
    }

    public function isReady(): bool
    {
        return $this->status === DocumentStatus::Ready;
    }

    /**
     * The only supported way to look a document up on behalf of a person.
     *
     * Scoping on the workspace rather than filtering afterwards is what makes a
     * cross-workspace probe by autoincrement id or by public ULID indistinguishable from a
     * document that does not exist.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeInWorkspace(Builder $query, Workspace $workspace): Builder
    {
        return $query->where('workspace_id', $workspace->getKey());
    }

    protected static function newFactory(): Factory
    {
        return DocumentFactory::new();
    }
}
