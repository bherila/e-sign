<?php

declare(strict_types=1);

namespace App\Domain\Preparation\Documents\Models;

use App\Domain\Preparation\Documents\RevisionKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One immutable revision of a document.
 *
 * Immutability is the point of the table, so it is enforced here as well as by the key
 * layout: a revision refuses updates and deletes, exactly like an audit event, and for the
 * same portability reason (a database trigger would not behave identically on SQLite,
 * MySQL, and MariaDB). A correction is a new revision, never an edit of an old one, because
 * invariant 2 in docs/ARCHITECTURE.md binds an acceptance to a specific review revision and
 * a mutable revision would silently move what a signer agreed to.
 *
 * `disk` and `path` never appear in an HTTP response.
 *
 * @property int $id
 * @property string $public_id
 * @property int $document_id
 * @property RevisionKind $kind
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $bytes
 * @property int|null $page_count
 * @property array<string, mixed> $normalization
 * @property int|null $created_by
 */
class DocumentRevision extends Model
{
    /** Written once; there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'kind',
        'disk',
        'path',
        'sha256',
        'bytes',
        'page_count',
        'normalization',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RevisionKind::class,
            'normalization' => 'array',
            'bytes' => 'integer',
            'page_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $revision): void {
            if (($revision->public_id ?? '') === '') {
                $revision->public_id = (string) Str::ulid();
            }
        });

        static::updating(static function (self $revision): never {
            throw new RuntimeException(
                'document_revisions is immutable; record a new revision instead of updating one.',
            );
        });

        static::deleting(static function (self $revision): never {
            throw new RuntimeException(
                'document_revisions is immutable; a revision cannot be deleted.',
            );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A filename safe to put in a Content-Disposition header.
     *
     * Built from the document title, never from the uploaded filename, and reduced to an
     * ASCII slug so nothing in it can carry a quote, a path separator, a control character,
     * or a right-to-left override. The digest prefix makes two downloads of different
     * revisions distinguishable on disk.
     */
    public function downloadFilename(string $title): string
    {
        $slug = Str::slug(Str::limit($title, 80, ''));

        if ($slug === '') {
            $slug = 'document';
        }

        return sprintf('%s-%s-%s.pdf', $slug, $this->kind->value, substr($this->sha256, 0, 12));
    }
}
